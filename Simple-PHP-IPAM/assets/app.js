(function(){
  const key = "ipam_theme";
  const saved = localStorage.getItem(key);
  if (saved === "light" || saved === "dark") {
    document.documentElement.setAttribute("data-theme", saved);
  }

  window.ipamToggleTheme = function(){
    const cur = document.documentElement.getAttribute("data-theme");
    let next;
    if (cur === "dark") next = "light";
    else if (cur === "light") next = "dark";
    else {
      next = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "light" : "dark";
    }
    document.documentElement.setAttribute("data-theme", next);
    localStorage.setItem(key, next);
  };

  window.ipamClearTheme = function(){
    document.documentElement.removeAttribute("data-theme");
    localStorage.removeItem(key);
  };
})();
