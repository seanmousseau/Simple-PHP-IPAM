#!/usr/bin/env python3
# gen_sample_dataset.py — reproducible sample address CSV for Simple PHP IPAM
#
# Covers (v1.11):
#   - ip, cidr, hostname, owner, note, status, group
#   - Six sites: HQ London, Data Centre Primary, DC DR, Manchester, Edinburgh, Bristol
#   - IPv4: /23–/32 subnets; IPv6: /64 and /128
#   - All three status values (used / reserved / free)
#   - All address types: servers, workstations, VoIP, printers, cameras, K8s nodes,
#     guest WiFi DHCP pool, VPN pool, P2P /30 and /31 WAN links, loopbacks
#   - Group field populated for every row; values include tier names (web-tier, db-tier,
#     app-tier), function labels (infra, monitoring, backup, devops, security, storage,
#     mail, vpn, wan, loopbacks, oob-mgmt, guest-wifi, vpn-remote, control-plane, worker),
#     floor tags (floor-1 … floor-4), and camera area tags
#   - Note: vlan_id is a subnet-level field (not a CSV column); VLAN assignments are
#     shown in address notes for reference and must be set on subnets after import.
#
# Regenerate: python3 gen_sample_dataset.py > sample_dataset.csv
import ipaddress, csv, sys, random
random.seed(42)
W = csv.writer(sys.stdout)
W.writerow(["ip","cidr","hostname","owner","note","status","group"])
departments = ["Network Ops","Security","IT Infrastructure","DevOps","Finance","HR","Marketing","Legal","Sales","R&D","Facilities","Helpdesk","Executive","Procurement","Compliance"]
server_names = ["web","db","app","api","mail","vpn","proxy","nfs","ldap","radius","syslog","monitor","backup","ansible","git","ci","artifact","vault","kafka","redis","elastic","kibana","grafana","zabbix","netbox","wiki","jira","confluence","nexus","harbor","k8s-master","k8s-worker","etcd"]
server_groups = {
    "web":"web-tier","api":"web-tier","proxy":"web-tier",
    "db":"db-tier","redis":"db-tier","kafka":"db-tier","elastic":"db-tier",
    "app":"app-tier","artifact":"app-tier","nexus":"app-tier","harbor":"app-tier",
    "mail":"mail","vpn":"vpn",
    "nfs":"storage",
    "ldap":"infra","radius":"infra","netbox":"infra",
    "syslog":"monitoring","monitor":"monitoring","grafana":"monitoring","zabbix":"monitoring","kibana":"monitoring",
    "backup":"backup",
    "ansible":"devops","git":"devops","ci":"devops","k8s-master":"devops","k8s-worker":"devops","etcd":"devops",
    "vault":"security",
    "wiki":"collaboration","jira":"collaboration","confluence":"collaboration",
}
camera_areas = ["entrance","lobby","server-room","car-park","reception","corridor","warehouse","loading-bay"]
def rnd_owner(): return random.choice(departments)
def rnd_note_server(role, vlan=None):
    extras = ["Production","Staging","DR standby","HA pair node A","HA pair node B","Scheduled for decom Q3","Rebuilt 2026-01","New build","Legacy system","Patched 2026-03","SELinux enforcing","Windows Server 2022","Ubuntu 24.04 LTS","RHEL 9.2","Ansible-managed"]
    base = f"{role.title()} server — {random.choice(extras)}"
    return f"{base} [VLAN {vlan}]" if vlan else base
def host(ip,cidr,hostname,owner,note,status,group=""): W.writerow([ip,cidr,hostname,owner,note,status,group])
def fill_subnet(cidr_str,label,site_prefix,used_pct=0.7,reserved_gw=True,host_type="workstation",owner_override=None,group_override=None,vlan=None):
    net=ipaddress.ip_network(cidr_str,strict=False)
    hosts=list(net.hosts()) if net.prefixlen<31 else list(net)
    if not hosts: return
    idx=0
    vlan_note = f" [VLAN {vlan}]" if vlan else ""
    if reserved_gw and len(hosts)>4 and net.prefixlen<=30:
        for h,hn,own,note,st in [(hosts[0],f"gw-{site_prefix}","Network Ops",f"Default gateway — {label}{vlan_note}","reserved"),(hosts[1],f"dns1-{site_prefix}","Network Ops",f"Primary DNS — {label}{vlan_note}","reserved"),(hosts[2],f"dns2-{site_prefix}","Network Ops",f"Secondary DNS — {label}{vlan_note}","reserved"),(hosts[3],f"dhcp-{site_prefix}","Network Ops",f"DHCP server — {label}{vlan_note}","reserved")]:
            host(str(h),cidr_str,f"{hn}.corp.example.com",own,note,st,"infra")
        idx=4
    used_count=int((len(hosts)-idx)*used_pct)
    name_i=1
    floors = ["floor-1","floor-2","floor-3","floor-4"]
    for i,h in enumerate(hosts[idx:],start=idx):
        if (i-idx)>=used_count: continue
        if host_type=="workstation":
            hostname=f"ws-{site_prefix}-{i:03d}.corp.example.com"; note=f"Workstation — {rnd_owner()}, assigned to user{i:04d}{vlan_note}"; status="used"
            grp=group_override or floors[(i-idx)%len(floors)]
        elif host_type=="server":
            sn=server_names[(name_i-1)%len(server_names)]; hostname=f"{sn}{name_i:02d}-{site_prefix}.corp.example.com"; note=rnd_note_server(sn,vlan); status="used"
            grp=group_override or server_groups.get(sn,"infra")
        elif host_type=="voip":
            hostname=f"phone-{site_prefix}-{i:03d}.corp.example.com"; note=f"IP phone — extension {2000+i}{vlan_note}"; status="used"
            grp=group_override or floors[(i-idx)%len(floors)]
        elif host_type=="printer":
            hostname=f"print-{site_prefix}-{i:02d}.corp.example.com"; note=f"Network printer — {rnd_owner()}{vlan_note}"; status="used"
            grp=group_override or floors[(i-idx)%2]
        elif host_type=="camera":
            hostname=f"cam-{site_prefix}-{i:02d}.corp.example.com"; note=f"IP camera — {label} area{vlan_note}"; status="used"
            grp=group_override or camera_areas[(i-idx)%len(camera_areas)]
        elif host_type=="guest":
            hostname=""; note=f"Guest WiFi DHCP pool{vlan_note}"; status=random.choice(["used","free","free"])
            grp="guest-wifi"
        elif host_type=="vpn":
            hostname=f"vpn-{site_prefix}-{i:03d}.corp.example.com"; note=f"VPN client pool — {rnd_owner()}{vlan_note}"; status="reserved"
            grp="vpn-remote"
        elif host_type=="k8s":
            hostname=f"k8s-{site_prefix}-{name_i:02d}.corp.example.com"; note=f"Kubernetes node — cluster {site_prefix}{vlan_note}"; status="used"
            grp="control-plane" if name_i<=3 else "worker"
        else:
            hostname=f"host-{site_prefix}-{i:03d}"; note=""; status="used"; grp=""
        host(str(h),cidr_str,hostname,owner_override or rnd_owner(),note,status,grp)
        name_i+=1
def p2p_link(cidr_str,a_name,b_name,owner="Network Ops"):
    net=ipaddress.ip_network(cidr_str,strict=False)
    hosts=list(net.hosts()) if net.prefixlen<31 else list(net)
    if len(hosts)>=2:
        host(str(hosts[0]),cidr_str,a_name,owner,f"P2P link — {a_name} side","reserved","wan")
        host(str(hosts[1]),cidr_str,b_name,owner,f"P2P link — {b_name} side","reserved","wan")
def loopback(ip,cidr,name,owner="Network Ops",note="",grp="loopbacks"): host(ip,cidr,name,owner,note or f"Loopback — {name}","reserved",grp)
def single(ip,cidr,hostname,owner,note,status="used",grp=""): host(ip,cidr,hostname,owner,note,status,grp)

# HQ London
# VLANs: 10=Mgmt, 20=Servers-Linux, 21=Servers-Win, 100=Workstations, 110=Printers,
#         120=Cameras, 200=DMZ, 300=Guest-WiFi, 301=Corp-WiFi
fill_subnet("10.0.1.0/24","HQ Management","hq-mgmt",0.55,True,"server","Network Ops",vlan=10)
fill_subnet("10.0.2.0/25","HQ Linux Servers","hq-lnx",0.85,True,"server","IT Infrastructure",vlan=20)
fill_subnet("10.0.2.128/25","HQ Windows Servers","hq-win",0.80,True,"server","IT Infrastructure",vlan=21)
fill_subnet("10.0.10.0/23","HQ Workstations","hq-ws",0.75,True,"workstation",vlan=100)
fill_subnet("10.0.20.0/28","HQ Printers","hq-prt",0.90,False,"printer","Facilities",vlan=110)
fill_subnet("10.0.21.0/27","HQ IP Cameras","hq-cam",0.80,False,"camera","Facilities",vlan=120)
fill_subnet("10.0.100.0/24","HQ DMZ","hq-dmz",0.60,True,"server","Security",vlan=200)
fill_subnet("10.0.200.0/24","HQ Guest WiFi","hq-gst",0.40,True,"guest","IT Infrastructure",vlan=300)
fill_subnet("10.0.201.0/24","HQ Corporate WiFi","hq-cwifi",0.65,True,"workstation","IT Infrastructure",vlan=301)
for i,(name,note) in enumerate([("hq-core-sw01","Core switch 1 loopback"),("hq-core-sw02","Core switch 2 loopback"),("hq-fw01","Firewall 1 loopback"),("hq-fw02","Firewall 2 loopback"),("hq-rtr01","Router 1 loopback"),("hq-rtr02","Router 2 loopback"),("hq-nms01","NMS server loopback")]):
    loopback(f"10.0.255.{i+1}","10.0.255.0/28",f"{name}.hq.example.com","Network Ops",note)

# Data Center Primary
# VLANs: 400=Compute, 401=Storage, 402=OOB-IPMI, 403=DMZ, 410=Kubernetes,
#         420=Monitoring, 430=CI-CD, 498=OOB-Mgmt
fill_subnet("10.10.1.0/24","DC Compute","dc-cmp",0.90,True,"server","IT Infrastructure",vlan=400)
fill_subnet("10.10.2.0/24","DC Storage","dc-sto",0.70,True,"server","IT Infrastructure",vlan=401)
fill_subnet("10.10.3.0/24","DC OOB IPMI","dc-oob",0.80,True,"server","IT Infrastructure",group_override="oob-mgmt",vlan=402)
fill_subnet("10.10.4.0/24","DC DMZ","dc-dmz",0.55,True,"server","Security",vlan=403)
fill_subnet("10.10.10.0/24","DC Kubernetes","dc-k8s",0.85,True,"k8s","DevOps",vlan=410)
fill_subnet("10.10.20.0/24","DC Monitoring","dc-mon",0.50,True,"server","Network Ops",group_override="monitoring",vlan=420)
fill_subnet("10.10.30.0/24","DC CI-CD","dc-ci",0.60,True,"server","DevOps",group_override="devops",vlan=430)
hosts29=list(ipaddress.ip_network("10.10.255.248/29").hosts())
for i,(name,note) in enumerate([("dc-oob-sw01","OOB switch 1 [VLAN 498]"),("dc-oob-sw02","OOB switch 2 [VLAN 498]"),("dc-oob-pdu01","PDU rack A [VLAN 498]"),("dc-oob-pdu02","PDU rack B [VLAN 498]"),("dc-console","Console server [VLAN 498]"),("dc-kvm01","KVM over IP [VLAN 498]")]):
    if i<len(hosts29): host(str(hosts29[i]),"10.10.255.248/29",f"{name}.dc.example.com","Network Ops",note,"reserved","oob-mgmt")

# DC DR
# VLANs: 500=Compute, 501=Storage, 502=OOB, 510=Kubernetes
fill_subnet("10.20.1.0/24","DR Compute","dr-cmp",0.60,True,"server","IT Infrastructure",vlan=500)
fill_subnet("10.20.2.0/24","DR Storage","dr-sto",0.45,True,"server","IT Infrastructure",vlan=501)
fill_subnet("10.20.3.0/24","DR OOB","dr-oob",0.40,True,"server","IT Infrastructure",group_override="oob-mgmt",vlan=502)
fill_subnet("10.20.10.0/24","DR Kubernetes","dr-k8s",0.50,True,"k8s","DevOps",vlan=510)

# Branch Manchester
# VLANs: 600=Staff, 601=Guest-WiFi, 602=VoIP, 603=Printers
fill_subnet("10.30.0.0/24","MCR Staff","mcr-ws",0.70,True,"workstation","IT Infrastructure",vlan=600)
fill_subnet("10.30.1.0/24","MCR Guest WiFi","mcr-gst",0.30,True,"guest","IT Infrastructure",vlan=601)
fill_subnet("10.30.2.0/24","MCR VoIP","mcr-voip",0.80,True,"voip","IT Infrastructure",vlan=602)
fill_subnet("10.30.3.0/28","MCR Printers","mcr-prt",0.80,False,"printer","Facilities",vlan=603)
for i,(sn,note,grp) in enumerate([("dc","Domain controller — Manchester [VLAN 600]","infra"),("fs","File server — Manchester [VLAN 600]","storage"),("print-srv","Print server — Manchester [VLAN 603]","infra"),("voip-gw","VoIP gateway — Manchester [VLAN 602]","voip"),("backup","Backup agent — Manchester [VLAN 600]","backup")],1):
    single(f"10.30.0.{240+i}","10.30.0.0/24",f"{sn}-mcr.corp.example.com","IT Infrastructure",note,"used",grp)

# Branch Edinburgh
# VLANs: 700=Staff, 701=Guest-WiFi, 702=VoIP, 703=Printers
fill_subnet("10.40.0.0/24","EDI Staff","edi-ws",0.65,True,"workstation","IT Infrastructure",vlan=700)
fill_subnet("10.40.1.0/24","EDI Guest WiFi","edi-gst",0.25,True,"guest","IT Infrastructure",vlan=701)
fill_subnet("10.40.2.0/25","EDI VoIP","edi-voip",0.70,True,"voip","IT Infrastructure",vlan=702)
fill_subnet("10.40.3.0/28","EDI Printers","edi-prt",0.70,False,"printer","Facilities",vlan=703)
for i,(sn,note,grp) in enumerate([("dc","Domain controller — Edinburgh [VLAN 700]","infra"),("fs","File server — Edinburgh [VLAN 700]","storage"),("voip-gw","VoIP gateway — Edinburgh [VLAN 702]","voip")],1):
    single(f"10.40.0.{240+i}","10.40.0.0/24",f"{sn}-edi.corp.example.com","IT Infrastructure",note,"used",grp)

# Branch Bristol
# VLANs: 800=Staff, 801=Guest-WiFi, 802=Printers
fill_subnet("10.50.0.0/24","BRS Staff","brs-ws",0.60,True,"workstation","IT Infrastructure",vlan=800)
fill_subnet("10.50.1.0/25","BRS Guest WiFi","brs-gst",0.20,True,"guest","IT Infrastructure",vlan=801)
fill_subnet("10.50.2.0/28","BRS Printers","brs-prt",0.70,False,"printer","Facilities",vlan=802)
for i,(sn,note,grp) in enumerate([("dc","Domain controller — Bristol [VLAN 800]","infra"),("fs","File server — Bristol [VLAN 800]","storage")],1):
    single(f"10.50.0.{240+i}","10.50.0.0/24",f"{sn}-brs.corp.example.com","IT Infrastructure",note,"used",grp)

# VPN Pool (no VLAN — routed through firewall)
fill_subnet("172.16.0.0/22","VPN Client Pool","vpn",0.45,True,"vpn","Security")

# P2P /30 WAN links
for cidr,a,b in [("172.16.4.0/30","hq-rtr01.hq.example.com","dc-rtr01.dc.example.com"),("172.16.4.4/30","hq-rtr01.hq.example.com","mcr-rtr01.br.example.com"),("172.16.4.8/30","hq-rtr01.hq.example.com","edi-rtr01.br.example.com"),("172.16.4.12/30","hq-rtr01.hq.example.com","brs-rtr01.br.example.com"),("172.16.4.16/30","dc-rtr01.dc.example.com","dr-rtr01.dc.example.com"),("172.16.4.20/30","dc-rtr01.dc.example.com","mcr-rtr01.br.example.com"),("172.16.4.24/30","dc-rtr01.dc.example.com","edi-rtr01.br.example.com"),("172.16.4.28/30","dc-rtr01.dc.example.com","brs-rtr01.br.example.com"),("172.16.4.32/30","hq-rtr02.hq.example.com","dc-rtr02.dc.example.com"),("172.16.4.36/30","hq-rtr02.hq.example.com","dr-rtr01.dc.example.com")]:
    p2p_link(cidr,a,b)

# P2P /31 peering links
for cidr,a,b in [("172.16.5.0/31","hq-rtr01.hq.example.com","hq-rtr02.hq.example.com"),("172.16.5.2/31","dc-rtr01.dc.example.com","dc-rtr02.dc.example.com"),("172.16.5.4/31","dr-rtr01.dc.example.com","dr-rtr02.dc.example.com"),("172.16.5.6/31","mcr-rtr01.br.example.com","mcr-rtr02.br.example.com"),("172.16.5.8/31","edi-rtr01.br.example.com","edi-rtr02.br.example.com"),("172.16.5.10/31","brs-rtr01.br.example.com","brs-rtr02.br.example.com")]:
    p2p_link(cidr,a,b)

# Router loopbacks /32
for ip,name,note in [("172.16.100.1","hq-rtr01","HQ primary router"),("172.16.100.2","hq-rtr02","HQ secondary router"),("172.16.100.3","dc-rtr01","DC primary router"),("172.16.100.4","dc-rtr02","DC secondary router"),("172.16.100.5","dr-rtr01","DR primary router"),("172.16.100.6","dr-rtr02","DR secondary router"),("172.16.100.7","mcr-rtr01","Manchester router"),("172.16.100.8","mcr-rtr02","Manchester router 2"),("172.16.100.9","edi-rtr01","Edinburgh router"),("172.16.100.10","edi-rtr02","Edinburgh router 2"),("172.16.100.11","brs-rtr01","Bristol router"),("172.16.100.12","brs-rtr02","Bristol router 2")]:
    loopback(ip,"172.16.100.0/28",f"{name}.corp.example.com","Network Ops",f"{note} loopback")

# IPv6 — HQ Servers /64
for ip,hostname,owner,note,grp in [
    ("2001:db8:1:1::1","web01-v6.hq.example.com","IT Infrastructure","Web server primary IPv6","web-tier"),
    ("2001:db8:1:1::2","web02-v6.hq.example.com","IT Infrastructure","Web server HA IPv6","web-tier"),
    ("2001:db8:1:1::3","api01-v6.hq.example.com","DevOps","API server primary IPv6","web-tier"),
    ("2001:db8:1:1::4","api02-v6.hq.example.com","DevOps","API server HA IPv6","web-tier"),
    ("2001:db8:1:1::5","mail01-v6.hq.example.com","IT Infrastructure","Mail server IPv6","mail"),
    ("2001:db8:1:1::6","mail02-v6.hq.example.com","IT Infrastructure","Mail server HA IPv6","mail"),
    ("2001:db8:1:1::10","db01-v6.hq.example.com","IT Infrastructure","Database primary IPv6","db-tier"),
    ("2001:db8:1:1::11","db02-v6.hq.example.com","IT Infrastructure","Database replica IPv6","db-tier"),
    ("2001:db8:1:1::12","db03-v6.hq.example.com","IT Infrastructure","Database replica 2 IPv6","db-tier"),
    ("2001:db8:1:1::20","proxy01-v6.hq.example.com","Security","Forward proxy IPv6","web-tier"),
    ("2001:db8:1:1::21","proxy02-v6.hq.example.com","Security","Forward proxy HA IPv6","web-tier"),
    ("2001:db8:1:1::fe","gw-srv-v6.hq.example.com","Network Ops","Server VLAN IPv6 gateway","infra"),
]:
    host(ip,"2001:db8:1:1::/64",hostname,owner,note,"reserved" if "gateway" in note else "used",grp)

# IPv6 — HQ Workstations /64
for i in range(1,21):
    host(f"2001:db8:1:2::{i:x}","2001:db8:1:2::/64",f"ws{i:03d}-v6.hq.example.com","IT Infrastructure",f"Workstation {i:03d} IPv6 dual-stack","used",f"floor-{((i-1)%4)+1}")
host("2001:db8:1:2::fe","2001:db8:1:2::/64","gw-ws-v6.hq.example.com","Network Ops","Workstation VLAN IPv6 gateway","reserved","infra")

# IPv6 — DC Compute /64
for ip,hostname,owner,note,grp in [
    ("2001:db8:10:1::1","dc-svc01-v6.dc.example.com","IT Infrastructure","DC service node 1 IPv6","app-tier"),
    ("2001:db8:10:1::2","dc-svc02-v6.dc.example.com","IT Infrastructure","DC service node 2 IPv6","app-tier"),
    ("2001:db8:10:1::3","dc-svc03-v6.dc.example.com","IT Infrastructure","DC service node 3 IPv6","app-tier"),
    ("2001:db8:10:1::10","k8s01-dc-v6.dc.example.com","DevOps","K8s master 1 IPv6","control-plane"),
    ("2001:db8:10:1::11","k8s02-dc-v6.dc.example.com","DevOps","K8s master 2 IPv6","control-plane"),
    ("2001:db8:10:1::12","k8s03-dc-v6.dc.example.com","DevOps","K8s master 3 IPv6","control-plane"),
    ("2001:db8:10:1::20","k8s-wrk01-v6.dc.example.com","DevOps","K8s worker 1 IPv6","worker"),
    ("2001:db8:10:1::21","k8s-wrk02-v6.dc.example.com","DevOps","K8s worker 2 IPv6","worker"),
    ("2001:db8:10:1::22","k8s-wrk03-v6.dc.example.com","DevOps","K8s worker 3 IPv6","worker"),
    ("2001:db8:10:1::23","k8s-wrk04-v6.dc.example.com","DevOps","K8s worker 4 IPv6","worker"),
    ("2001:db8:10:1::fe","dc-gw-v6.dc.example.com","Network Ops","DC compute VLAN IPv6 gateway","infra"),
]:
    host(ip,"2001:db8:10:1::/64",hostname,owner,note,"reserved" if "gateway" in note else "used",grp)

# IPv6 /128 loopbacks
for ip,hostname,note in [("2001:db8:ff::1","hq-rtr01-lo.hq.example.com","HQ router 1 IPv6 loopback"),("2001:db8:ff::2","hq-rtr02-lo.hq.example.com","HQ router 2 IPv6 loopback"),("2001:db8:ff::3","dc-rtr01-lo.dc.example.com","DC router 1 IPv6 loopback"),("2001:db8:ff::4","dc-rtr02-lo.dc.example.com","DC router 2 IPv6 loopback"),("2001:db8:ff::5","dr-rtr01-lo.dc.example.com","DR router 1 IPv6 loopback"),("2001:db8:ff::6","mcr-rtr01-lo.br.example.com","MCR router IPv6 loopback"),("2001:db8:ff::7","edi-rtr01-lo.br.example.com","EDI router IPv6 loopback"),("2001:db8:ff::8","brs-rtr01-lo.br.example.com","BRS router IPv6 loopback")]:
    loopback(ip,f"{ip}/128",hostname,"Network Ops",note)
