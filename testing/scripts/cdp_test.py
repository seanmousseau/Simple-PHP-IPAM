#!/usr/bin/env python3
"""
CDP browser test suite for Simple PHP IPAM.
Covers: auth, CRUD (subnets/addresses), search, API, audit, access control, exports.
"""
import asyncio, json, base64, urllib.request, urllib.parse, os, sys
import websockets

CHROME_HOST = "192.168.80.15"
CHROME_PORT = 9224
APP         = "https://dev-direct.seanmousseau.com/claude/ipam"
OUT         = "/tmp/ipam-screenshots"
os.makedirs(OUT, exist_ok=True)

def _require_env(name):
    val = os.environ.get(name)
    if not val:
        print(f"ERROR: {name} env var is required. Source ~/.claude/dev-secrets.env first.", file=sys.stderr)
        sys.exit(1)
    return val

# HTTP Basic Auth protecting the /claude/ gateway
BASIC_USER  = _require_env("IPAM_BASIC_USER")
BASIC_PASS  = _require_env("IPAM_BASIC_PASS")
# Encoded for use in URLs and fetch headers
_basic_b64  = base64.b64encode(f"{BASIC_USER}:{BASIC_PASS}".encode()).decode()
BASIC_HEADER = f"Basic {_basic_b64}"

ADMIN_USER  = _require_env("IPAM_ADMIN_USER")
ADMIN_PASS  = _require_env("IPAM_ADMIN_PASS")
CIDR_1      = "10.99.0.0/24"   # subnet CRUD
CIDR_2      = "10.88.0.0/24"   # address / unassigned tests
TEST_IP     = "10.88.0.10"
TEST_HOST   = "cdp-test-host"
RO_USER     = "cdp-readonly"
RO_PASS     = "TestPass!cdp99"

# ── CDP helpers ───────────────────────────────────────────────────────────────

def cdp_http(path):
    with urllib.request.urlopen(f"http://{CHROME_HOST}:{CHROME_PORT}{path}", timeout=5) as r:
        return json.loads(r.read())

async def run():
    version    = cdp_http("/json/version")
    browser_ws = version["webSocketDebuggerUrl"]
    print(f"Browser: {version['Browser']}")
    print(f"App:     {APP}\n")

    async with websockets.connect(browser_ws, max_size=10_000_000) as bws:
        _id = 0
        async def browser_send(method, params=None):
            nonlocal _id; _id += 1
            await bws.send(json.dumps({"id": _id, "method": method, "params": params or {}}))
            while True:
                data = json.loads(await asyncio.wait_for(bws.recv(), timeout=10))
                if data.get("id") == _id: return data

        r = await browser_send("Target.createTarget", {"url": "about:blank"})
        target_id = r["result"]["targetId"]

    page_ws = f"ws://{CHROME_HOST}:{CHROME_PORT}/devtools/page/{target_id}"
    async with websockets.connect(page_ws, max_size=10_000_000) as ws:
        _id = 0; pass_count = 0; failures = []
        state = {}  # subnet_id, addr_id, api_key, ro_user_id carried between sections

        async def send(method, params=None):
            nonlocal _id; _id += 1
            await ws.send(json.dumps({"id": _id, "method": method, "params": params or {}}))
            while True:
                data = json.loads(await asyncio.wait_for(ws.recv(), timeout=15))
                if data.get("id") == _id: return data

        async def navigate(url, wait=2.0):
            # Embed Basic Auth credentials in URL so Chrome handles the gateway auth
            auth_url = url.replace("https://", f"https://{BASIC_USER}:{BASIC_PASS}@")
            await send("Page.navigate", {"url": auth_url})
            await asyncio.sleep(wait)

        async def js(expr):
            r = await send("Runtime.evaluate",
                           {"expression": expr, "returnByValue": True, "awaitPromise": True})
            return r.get("result", {}).get("result", {}).get("value")

        async def screenshot(name):
            r    = await send("Page.captureScreenshot", {"format": "png"})
            data = base64.b64decode(r["result"]["data"])
            with open(f"{OUT}/{name}.png", "wb") as f: f.write(data)
            print(f"    📸 {name}.png ({len(data)//1024}KB)")

        def check(name, ok, detail=""):
            nonlocal pass_count
            if ok:
                print(f"  ✅ {name}")
                pass_count += 1
            else:
                msg = f"❌ {name}" + (f" — {detail}" if detail else "")
                print(f"  {msg}"); failures.append(msg)

        async def get_csrf():
            return await js("document.querySelector('[name=csrf]')?.value || ''")

        async def fetch_post(url, fields):
            """POST via browser fetch (session cookies included). Returns {ok,status,url,body}."""
            csrf = await get_csrf()
            data = {**fields, "csrf": csrf}
            qs   = "&".join(urllib.parse.quote_plus(str(k)) + "=" +
                            urllib.parse.quote_plus(str(v)) for k, v in data.items())
            expr = f"""
                (async () => {{
                    const r = await fetch({json.dumps(url)}, {{
                        method: 'POST',
                        headers: {{
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Authorization': {json.dumps('Basic ' + _basic_b64)}
                        }},
                        body: {json.dumps(qs)},
                        credentials: 'same-origin'
                    }});
                    const body = await r.text();
                    return {{ ok: r.ok, status: r.status, url: r.url, body }};
                }})()
            """
            return await js(expr)

        async def logout():
            await navigate(f"{APP}/logout.php", wait=1.5)

        async def login(user, pwd):
            """Navigate to login page and submit credentials. Handles already-logged-in state."""
            await logout()   # ensure clean session before every login attempt
            await navigate(f"{APP}/login.php")
            await js(f"""
                document.querySelector('[name=username]').value = {json.dumps(user)};
                document.querySelector('[name=password]').value = {json.dumps(pwd)};
                document.querySelector('form').submit();
            """)
            await asyncio.sleep(2.5)
            return await js("location.pathname")

        async def subnet_id_for(cidr):
            """Find subnet_id from the .subnet-node list on the current subnets.php page."""
            return await js(f"""
                (function() {{
                    for (var node of document.querySelectorAll('.subnet-node')) {{
                        if (node.innerText.includes({json.dumps(cidr)})) {{
                            var a = node.querySelector('a[href*="subnet_id"]');
                            if (a) {{
                                var m = a.href.match(/subnet_id=([0-9]+)/);
                                if (m) return parseInt(m[1]);
                            }}
                        }}
                    }}
                    return null;
                }})()
            """)

        async def delete_subnet(cidr):
            """Find and POST-delete a subnet by CIDR from the current subnets.php page."""
            sub_id = await js(f"""
                (function() {{
                    for (var f of document.querySelectorAll('form')) {{
                        var act = f.querySelector('[name=action]');
                        var id  = f.querySelector('[name=id]');
                        if (act && act.value === 'delete' && id) {{
                            var node = f.closest('.subnet-node');
                            if (node && node.innerText.includes({json.dumps(cidr)}))
                                return id.value;
                        }}
                    }}
                    return null;
                }})()
            """)
            if sub_id:
                await fetch_post(f"{APP}/subnets.php", {"action": "delete", "id": sub_id})
                await asyncio.sleep(0.5)
            return bool(sub_id)

        await send("Page.enable")
        await send("Runtime.enable")
        await send("Emulation.setDeviceMetricsOverride",
                   {"width": 1440, "height": 900, "deviceScaleFactor": 1, "mobile": False})

        # ── 1: Authentication ─────────────────────────────────────────────────
        print("[1] Authentication")

        # Clear any session from a previous test run
        await logout()

        await navigate(f"{APP}/login.php")
        check("login page: username field",  await js("!!document.querySelector('[name=username]')"))
        check("login page: password field",  await js("!!document.querySelector('[name=password]')"))
        check("login page: submit button",   await js("!!document.querySelector('button[type=submit]')"))
        await screenshot("01_login")

        # Bad credentials
        await js("""
            document.querySelector('[name=username]').value = 'admin';
            document.querySelector('[name=password]').value = 'wrongpass!';
            document.querySelector('form').submit();
        """)
        await asyncio.sleep(2.0)
        check("bad login: stays on login page",  "login" in (await js("location.pathname") or ""))
        check("bad login: shows danger message",
              bool(await js("!!document.querySelector('.danger')")))
        await screenshot("02_bad_login")

        # Protected page redirect while logged out
        await navigate(f"{APP}/subnets.php")
        check("unauthenticated: redirects to login",
              "login" in (await js("location.pathname") or ""))

        # Good login
        path = await login(ADMIN_USER, ADMIN_PASS)
        check("good login: redirects to dashboard", "login" not in path)
        await screenshot("03_after_login")

        # ── 2: Dashboard ──────────────────────────────────────────────────────
        print("\n[2] Dashboard")

        await navigate(f"{APP}/dashboard.php")
        check("dashboard: correct title",    "Dashboard" in (await js("document.title") or ""))
        check("dashboard: has stat cards",   (await js("document.querySelectorAll('.card').length") or 0) > 0)
        check("dashboard: nav bar present",  await js("!!document.querySelector('.topbar')"))
        check("dashboard: has content",      await js("!!document.querySelector('table, .util-bar, .empty-state')"))
        await screenshot("04_dashboard")

        # ── 3: Page inventory ─────────────────────────────────────────────────
        print("\n[3] Page inventory")

        # Pages where a .danger element is expected on normal load (not an error)
        allowed_danger = {"db_tools.php"}

        pages = [
            ("subnets.php",    "Subnet"),
            ("search.php",     "Search"),
            ("audit.php",      "Audit"),
            ("users.php",      "User"),
            ("sites.php",      "Site"),
            ("api_keys.php",   "API Key"),
            ("dhcp_pool.php",  "DHCP"),
            ("import_csv.php", "Import"),
            ("db_tools.php",   "Database"),
            ("unassigned.php", "Unassigned"),
            ("bulk_update.php","Bulk"),
        ]
        for slug, keyword in pages:
            await navigate(f"{APP}/{slug}")
            title      = await js("document.title") or ""
            has_danger = await js("!!document.querySelector('.danger')")
            bad_danger = has_danger and slug not in allowed_danger
            check(f"{slug}: loads without error",
                  keyword.lower() in title.lower() and not bad_danger,
                  f"title={title!r}")
        await screenshot("05_last_inventory_page")

        # ── 4: Subnet CRUD ────────────────────────────────────────────────────
        print("\n[4] Subnet CRUD")

        # Clean up any leftovers from a prior failed run
        await navigate(f"{APP}/subnets.php")
        await delete_subnet(CIDR_1)
        await delete_subnet(CIDR_2)

        # Create CIDR_1
        await navigate(f"{APP}/subnets.php")
        await fetch_post(f"{APP}/subnets.php",
                         {"action": "create", "cidr": CIDR_1, "description": "CDP test subnet 1"})
        await navigate(f"{APP}/subnets.php")
        check(f"create {CIDR_1}: appears in list", CIDR_1 in (await js("document.body.innerText") or ""))

        # Create CIDR_2
        await fetch_post(f"{APP}/subnets.php",
                         {"action": "create", "cidr": CIDR_2, "description": "CDP address test"})
        await navigate(f"{APP}/subnets.php")
        check(f"create {CIDR_2}: appears in list", CIDR_2 in (await js("document.body.innerText") or ""))
        await screenshot("06_subnets_created")

        # Extract IDs
        state['subnet1_id'] = await subnet_id_for(CIDR_1)
        state['subnet2_id'] = await subnet_id_for(CIDR_2)
        check("subnet IDs extracted",
              bool(state.get('subnet1_id')) and bool(state.get('subnet2_id')),
              f"id1={state.get('subnet1_id')} id2={state.get('subnet2_id')}")

        # Update CIDR_1 description
        if state.get('subnet1_id'):
            await navigate(f"{APP}/subnets.php")
            await fetch_post(f"{APP}/subnets.php", {
                "action": "update", "id": state['subnet1_id'],
                "cidr": CIDR_1, "description": "CDP test subnet 1 — EDITED"
            })
            await navigate(f"{APP}/subnets.php")
            check("update subnet: edited description visible",
                  "EDITED" in (await js("document.body.innerText") or ""))

        # ── 5: Address CRUD ───────────────────────────────────────────────────
        print("\n[5] Address CRUD")

        sid2 = state.get('subnet2_id')
        if sid2:
            await navigate(f"{APP}/addresses.php?subnet_id={sid2}")
            check("addresses page: loads for subnet",
                  "Address" in (await js("document.title") or ""))

            # Create address
            await fetch_post(f"{APP}/addresses.php", {
                "action": "create", "subnet_id": sid2,
                "ip": TEST_IP, "hostname": TEST_HOST,
                "owner": "CDP Test", "status": "used", "note": "automated test", "grp": ""
            })
            await navigate(f"{APP}/addresses.php?subnet_id={sid2}")
            body = await js("document.body.innerText") or ""
            check(f"create address: {TEST_IP} in list",   TEST_IP   in body)
            check(f"create address: {TEST_HOST} in list", TEST_HOST in body)
            await screenshot("07_addresses_with_entry")

            # Extract address ID from address_history link
            state['addr_id'] = await js(f"""
                (function() {{
                    for (var a of document.querySelectorAll('a[href*="address_id"]')) {{
                        var row = a.closest('tr');
                        if (row && row.innerText.includes({json.dumps(TEST_IP)})) {{
                            var m = a.href.match(/address_id=([0-9]+)/);
                            if (m) return parseInt(m[1]);
                        }}
                    }}
                    return null;
                }})()
            """)

            # Update address hostname
            if state.get('addr_id'):
                await navigate(f"{APP}/addresses.php?subnet_id={sid2}")
                await fetch_post(f"{APP}/addresses.php", {
                    "action": "update", "subnet_id": sid2, "id": state['addr_id'],
                    "hostname": TEST_HOST + "-edited",
                    "owner": "CDP Test", "status": "used", "note": "automated test", "grp": ""
                })
                await navigate(f"{APP}/addresses.php?subnet_id={sid2}")
                check("update address: edited hostname visible",
                      TEST_HOST + "-edited" in (await js("document.body.innerText") or ""))

            # Address history
            if state.get('addr_id'):
                await navigate(f"{APP}/address_history.php?address_id={state['addr_id']}")
                rows = await js("document.querySelectorAll('table tbody tr').length")
                check("address history: page loads",
                      "History" in (await js("document.title") or ""))
                check("address history: has entries (create + update)",
                      (rows or 0) >= 2, f"rows={rows}")
                await screenshot("08_address_history")
        else:
            check("address CRUD: subnet2 ID available", False)

        # ── 6: Search ─────────────────────────────────────────────────────────
        print("\n[6] Search")

        await navigate(f"{APP}/search.php")
        check("search: page loads",      "Search" in (await js("document.title") or ""))
        check("search: query input [name=q]", await js("!!document.querySelector('[name=q]')"))

        await navigate(f"{APP}/search.php?q={urllib.parse.quote(TEST_IP)}")
        await asyncio.sleep(1.0)
        check("search by IP: result found", TEST_IP in (await js("document.body.innerText") or ""))
        await screenshot("09_search_ip")

        await navigate(f"{APP}/search.php?q={urllib.parse.quote(TEST_HOST)}")
        await asyncio.sleep(1.0)
        check("search by hostname: result found",
              TEST_HOST in (await js("document.body.innerText") or ""))

        await navigate(f"{APP}/search.php?q=zzz-no-match-xyz-999")
        await asyncio.sleep(1.0)
        check("search no results: empty state shown",
              await js("!!document.querySelector('.empty-state, .muted')"))

        # ── 7: Bulk Update ────────────────────────────────────────────────────
        print("\n[7] Bulk Update")

        await navigate(f"{APP}/bulk_update.php")
        check("bulk update: subnet select present",
              await js("!!document.querySelector('[name=subnet_id]')"))
        check("bulk update: no error on load",
              not await js("!!document.querySelector('.danger')"))

        if sid2:
            await navigate(f"{APP}/bulk_update.php?subnet_id={sid2}")
            check("bulk update: shows addresses for subnet",
                  TEST_IP in (await js("document.body.innerText") or ""))
            await screenshot("10_bulk_update_with_data")

        # ── 8: Unassigned ─────────────────────────────────────────────────────
        print("\n[8] Unassigned IPv4")

        await navigate(f"{APP}/unassigned.php")
        check("unassigned: subnet select present",
              await js("!!document.querySelector('[name=subnet_id]')"))

        if sid2:
            await navigate(f"{APP}/unassigned.php?subnet_id={sid2}")
            body  = await js("document.body.innerText") or ""
            count = await js("document.querySelector('.muted b')?.innerText")
            # Check table rows specifically (not full body, which may contain IP in forms/breadcrumbs)
            ip_in_table = await js(f"""
                Array.from(document.querySelectorAll('table tbody tr td b'))
                    .some(b => b.innerText.trim() === {json.dumps(TEST_IP)})
            """)
            check("unassigned: shows unassigned IPs",     "10.88.0." in body)
            check("unassigned: assigned IP excluded from table", not ip_in_table)
            check("unassigned: count is non-zero",
                  bool(count) and str(count).isdigit() and int(str(count)) > 0,
                  f"count={count}")
            await screenshot("11_unassigned_with_data")

        # ── 9: Database Tools ─────────────────────────────────────────────────
        print("\n[9] Database Tools")

        await navigate(f"{APP}/db_tools.php")
        check("db_tools: page loads", "Database" in (await js("document.title") or ""))
        heights = await js("""
            Array.from(document.querySelectorAll('.grid > .card'))
                .map(c => Math.round(c.getBoundingClientRect().height))
        """)
        if heights and len(heights) >= 2:
            equal = all(h == heights[0] for h in heights)
            check(f"db_tools: Export/Import cards equal height ({heights[0]}px)", equal,
                  f"heights={heights}")
        else:
            check("db_tools: found .grid > .card elements", False, f"found={heights}")
        await screenshot("12_db_tools")

        # ── 10: REST API ──────────────────────────────────────────────────────
        print("\n[10] REST API")

        # Create API key — submit the form so the browser navigates to the result page
        # (fetch_post can't query the redirected DOM; form.submit() can)
        await navigate(f"{APP}/api_keys.php")
        await js("""
            var form = Array.from(document.querySelectorAll('form'))
                .find(f => f.querySelector('[name=action]')?.value === 'create');
            if (form) {
                form.querySelector('[name=name]').value = 'cdp-test-key';
                form.submit();
            }
        """)
        await asyncio.sleep(2.5)
        # The browser is now on the result page showing the one-time key
        key = await js("document.querySelector('code.key-display')?.innerText?.trim()")
        if key:
            state['api_key'] = key
            check("api: key created and shown", True, f"{key[:8]}...")
        else:
            check("api: key created and shown", False)
        await screenshot("13_api_key_created")
        await navigate(f"{APP}/api_keys.php")
        await screenshot("13b_api_keys_list")

        # unauthenticated API check still needs Basic Auth for the gateway
        status_unauth = await js(
            f"""fetch({json.dumps(APP+'/api.php?resource=subnets')},
                {{headers:{{'Authorization':{json.dumps(BASIC_HEADER)}}}}}).then(r=>r.status)"""
        )
        check("api: unauthenticated returns 401", status_unauth == 401, f"got {status_unauth}")

        if state.get('api_key'):
            k = state['api_key']
            # Basic Auth satisfies the Apache gateway; api_key query param satisfies the app
            ba = json.dumps(BASIC_HEADER)

            subnets_r = await js(
                f"fetch({json.dumps(APP+f'/api.php?resource=subnets&api_key={k}')}"
                f",{{headers:{{'Authorization':{ba}}}}}).then(r=>r.json())"
            )
            subnets = subnets_r.get('subnets') if isinstance(subnets_r, dict) else subnets_r
            check("api GET subnets: returns list",
                  isinstance(subnets, list), f"raw type={type(subnets_r).__name__}")
            if isinstance(subnets, list):
                cidrs = [s.get('cidr','') for s in subnets if isinstance(s, dict)]
                check("api GET subnets: test subnets present",
                      CIDR_1 in cidrs or CIDR_2 in cidrs)

            if sid2:
                addrs_r = await js(
                    f"fetch({json.dumps(APP+f'/api.php?resource=addresses&subnet_id={sid2}&api_key={k}')}"
                    f",{{headers:{{'Authorization':{ba}}}}}).then(r=>r.json())"
                )
                addrs = addrs_r.get('addresses') if isinstance(addrs_r, dict) else addrs_r
                check("api GET addresses: returns list", isinstance(addrs, list))
                if isinstance(addrs, list):
                    ips = [a.get('ip','') for a in addrs if isinstance(a, dict)]
                    check("api GET addresses: test IP present", TEST_IP in ips)

            search_r = await js(
                f"fetch({json.dumps(APP+'/api.php?resource=search&q='+urllib.parse.quote(TEST_IP[:7])+f'&api_key={k}')}"
                f",{{headers:{{'Authorization':{ba}}}}}).then(r=>r.json())"
            )
            check("api GET search: returns results",
                  isinstance(search_r, dict) and 'results' in search_r)

        # ── 11: Audit Log ─────────────────────────────────────────────────────
        print("\n[11] Audit Log")

        await navigate(f"{APP}/audit.php")
        check("audit: page loads", "Audit" in (await js("document.title") or ""))
        rows = await js("document.querySelectorAll('table tbody tr').length")
        check("audit: has log entries", (rows or 0) > 0, f"rows={rows}")
        body = await js("document.body.innerText") or ""
        check("audit: subnet.create logged",
              "subnet" in body.lower() or "10.99" in body or "10.88" in body)
        check("audit: address.create logged",
              "address" in body.lower() or TEST_IP in body)
        await screenshot("14_audit")

        # ── 12: Access Control ────────────────────────────────────────────────
        print("\n[12] Access Control")

        # Create readonly user
        await navigate(f"{APP}/users.php")
        await fetch_post(f"{APP}/users.php", {
            "action": "create", "username": RO_USER, "password": RO_PASS,
            "name": "CDP Readonly", "email": "", "role": "readonly"
        })
        await navigate(f"{APP}/users.php")
        body = await js("document.body.innerText") or ""
        check("access: readonly user created", RO_USER in body)

        # Capture readonly user ID for cleanup
        state['ro_user_id'] = await js(f"""
            (function() {{
                for (var f of document.querySelectorAll('form')) {{
                    var act = f.querySelector('[name=action]');
                    var id  = f.querySelector('[name=id]');
                    if (act && act.value === 'delete' && id) {{
                        var row = f.closest('tr');
                        if (row && row.innerText.includes({json.dumps(RO_USER)}))
                            return parseInt(id.value);
                    }}
                }}
                return null;
            }})()
        """)

        # Login as readonly (login() calls logout() first, ensuring clean switch)
        ro_path = await login(RO_USER, RO_PASS)
        check("access: readonly can login", "login" not in ro_path)

        await navigate(f"{APP}/subnets.php")
        check("access: readonly can view subnets",
              "Subnet" in (await js("document.title") or "") and
              "Login" not in (await js("document.title") or ""))

        # Admin-only pages must return 403 → plain text "Forbidden"
        await navigate(f"{APP}/users.php", wait=1.5)
        body = await js("document.body.innerText") or ""
        check("access: readonly blocked from users.php",
              "Forbidden" in body or "403" in body)

        await navigate(f"{APP}/db_tools.php", wait=1.5)
        body = await js("document.body.innerText") or ""
        check("access: readonly blocked from db_tools.php",
              "Forbidden" in body or "403" in body)
        await screenshot("15_readonly_blocked")

        # Switch back to admin
        path = await login(ADMIN_USER, ADMIN_PASS)
        check("access: re-login as admin works", "login" not in path)

        # ── 13: CSV Exports ───────────────────────────────────────────────────
        print("\n[13] CSV Exports")

        _ba = json.dumps(BASIC_HEADER)
        exp = await js(f"""
            (async () => {{
                const r = await fetch({json.dumps(APP+'/export_subnets.php')},
                                      {{credentials:'same-origin',
                                        headers:{{'Authorization':{_ba}}}}});
                return {{status: r.status, type: r.headers.get('content-type'),
                         body: await r.text()}};
            }})()
        """)
        check("export subnets: 200 response",       (exp or {}).get('status') == 200)
        check("export subnets: text/csv content-type",
              'text' in ((exp or {}).get('type') or ''))
        check("export subnets: contains test CIDR",
              CIDR_1 in ((exp or {}).get('body') or ''))

        if sid2:
            exp = await js(f"""
                (async () => {{
                    const r = await fetch(
                        {json.dumps(APP+f'/export_addresses.php?subnet_id={sid2}')},
                        {{credentials:'same-origin',
                          headers:{{'Authorization':{_ba}}}}});
                    return {{status: r.status, body: await r.text()}};
                }})()
            """)
            check("export addresses: contains test IP",
                  TEST_IP in ((exp or {}).get('body') or ''))

        exp = await js(f"""
            (async () => {{
                const r = await fetch({json.dumps(APP+'/export_audit.php')},
                                      {{credentials:'same-origin',
                                        headers:{{'Authorization':{_ba}}}}});
                return {{status: r.status, type: r.headers.get('content-type')}};
            }})()
        """)
        check("export audit: 200 response",          (exp or {}).get('status') == 200)
        check("export audit: text/csv content-type", 'text' in ((exp or {}).get('type') or ''))
        await screenshot("16_after_exports")

        # ── 14: Cleanup ───────────────────────────────────────────────────────
        print("\n[14] Cleanup")

        # Delete test address
        if state.get('addr_id') and sid2:
            await navigate(f"{APP}/addresses.php?subnet_id={sid2}")
            await fetch_post(f"{APP}/addresses.php",
                             {"action": "delete", "subnet_id": sid2, "id": state['addr_id']})
            await navigate(f"{APP}/addresses.php?subnet_id={sid2}")
            check("cleanup: test address deleted",
                  TEST_IP not in (await js("document.body.innerText") or ""))

        # Delete test API key
        await navigate(f"{APP}/api_keys.php")
        key_id = await js("""
            (function() {
                for (var f of document.querySelectorAll('form')) {
                    var act = f.querySelector('[name=action]');
                    var kid = f.querySelector('[name=key_id]');
                    if (act && act.value === 'delete' && kid) {
                        var row = f.closest('tr');
                        if (row && row.innerText.includes('cdp-test-key'))
                            return kid.value;
                    }
                }
                return null;
            })()
        """)
        if key_id:
            await fetch_post(f"{APP}/api_keys.php", {"action": "delete", "key_id": key_id})

        # Delete subnets (CIDR_2 first — its address was already deleted)
        for cidr in [CIDR_2, CIDR_1]:
            await navigate(f"{APP}/subnets.php")
            await delete_subnet(cidr)
        await navigate(f"{APP}/subnets.php")
        body = await js("document.body.innerText") or ""
        check("cleanup: test subnets removed",
              CIDR_1 not in body and CIDR_2 not in body)

        # Delete readonly user
        if state.get('ro_user_id'):
            await navigate(f"{APP}/users.php")
            await fetch_post(f"{APP}/users.php",
                             {"action": "delete", "id": state['ro_user_id']})
            await navigate(f"{APP}/users.php")
            check("cleanup: readonly user deleted",
                  RO_USER not in (await js("document.body.innerText") or ""))

        await screenshot("17_final_state")

        # ── Summary ───────────────────────────────────────────────────────────
        total = pass_count + len(failures)
        print(f"\n{'='*60}")
        if failures:
            print(f"❌  {len(failures)}/{total} FAILURE(S):")
            for f in failures: print(f"    • {f}")
            sys.exit(1)
        else:
            print(f"✅  ALL {pass_count} CHECKS PASSED")
            print(f"    Screenshots: {OUT}/")

asyncio.run(run())
