// Progressive enhancement for the statically-generated docs site.
// Content, navigation, the front page and per-page install boxes are all rendered
// server-side; this script only layers on interactivity (theme, search filter, copy
// buttons, syntax highlighting, mobile sidebar, version switcher, install rotation).

function get_theme() {
    return document.documentElement.dataset.theme;
}

function set_theme(value) {
    document.documentElement.dataset.theme = value;
    localStorage.setItem("theme", value);
    const btn = document.getElementById("theme-toggle");
    if (btn) btn.textContent = value === "dark" ? "Light" : "Dark";
}

function init_theme() {
    const saved = localStorage.getItem("theme");
    const prefers_dark = matchMedia("(prefers-color-scheme: dark)").matches;
    set_theme(saved || (prefers_dark ? "dark" : "light"));
}

function toggle_theme() {
    set_theme(get_theme() === "dark" ? "light" : "dark");
}

function open_sidebar() {
    document.getElementById("sidebar").classList.add("open");
    document.getElementById("overlay").classList.add("open");
    document.getElementById("hamburger").style.display = "none";
}

function close_sidebar() {
    document.getElementById("sidebar").classList.remove("open");
    document.getElementById("overlay").classList.remove("open");
    document.getElementById("hamburger").style.display = "";
}

function filter_nav(query) {
    const q = query.toLowerCase();
    for (const link of document.querySelectorAll(".nav-link")) {
        if (!("slug" in link.dataset)) continue;
        link.style.display = q === "" || link.textContent.toLowerCase().includes(q) ? "" : "none";
    }
    for (const cat of document.querySelectorAll(".nav-category")) {
        let sib = cat.nextElementSibling;
        let visible = false;
        while (sib && !sib.classList.contains("nav-category")) {
            if (sib.classList.contains("nav-link") && sib.style.display !== "none") visible = true;
            sib = sib.nextElementSibling;
        }
        cat.style.display = visible ? "" : "none";
    }
}

function observe_fade_ins() {
    const observer = new IntersectionObserver(
        (entries) => entries.forEach((e) => {
            if (e.isIntersecting) { e.target.classList.add("visible"); observer.unobserve(e.target); }
        }),
        { threshold: 0.1 },
    );
    for (const el of document.querySelectorAll(".fade-in")) observer.observe(el);
}

function add_copy_buttons(container) {
    for (const pre of container.querySelectorAll("pre")) {
        const code = pre.querySelector("code");
        const btn = document.createElement("button");
        btn.className = "copy-btn";
        btn.textContent = "Copy";
        btn.addEventListener("click", () => {
            navigator.clipboard.writeText(code ? code.textContent : "").then(() => {
                btn.textContent = "Copied!";
                btn.classList.add("copied");
                setTimeout(() => { btn.textContent = "Copy"; btn.classList.remove("copied"); }, 1500);
            });
        });
        pre.appendChild(btn);
    }
}

function highlight_code(container, selector) {
    for (const block of container.querySelectorAll(selector)) {
        hljs.highlightElement(block);
    }
}

let _rotatingTimeout = null;

function start_rotating_install() {
    if (_rotatingTimeout) clearTimeout(_rotatingTimeout);
    if (typeof PACKAGES === "undefined") return;

    const el = document.getElementById("rotating-install");
    if (!el) return;

    const prefix = "composer require php-standard-library/";
    const slugs = Object.keys(PACKAGES);
    let index = 0;
    let phase = "wait";

    function get_suffix() {
        const pkg = PACKAGES[slugs[index]];
        return pkg.replace("php-standard-library/", "");
    }

    function tick() {
        const cursor = el.querySelector(".typing-cursor");
        const current = el.textContent;
        const currentSuffix = current.slice(prefix.length);

        if (phase === "wait") {
            phase = "delete";
            _rotatingTimeout = setTimeout(tick, 4000);
            return;
        }

        if (phase === "delete") {
            if (currentSuffix.length > 0) {
                el.innerHTML = ""; el.append(prefix + currentSuffix.slice(0, -1), cursor);
                _rotatingTimeout = setTimeout(tick, 30);
            } else {
                index = (index + 1) % slugs.length;
                el.href = slugs[index] + "/";
                phase = "type";
                _rotatingTimeout = setTimeout(tick, 100);
            }
            return;
        }

        if (phase === "type") {
            const target = get_suffix();
            const typed = currentSuffix.length;
            if (typed < target.length) {
                el.innerHTML = ""; el.append(prefix + target.slice(0, typed + 1), cursor);
                _rotatingTimeout = setTimeout(tick, 50);
            } else {
                phase = "wait";
                _rotatingTimeout = setTimeout(tick, 0);
            }
        }
    }

    phase = "wait";
    tick();
}

function current_slug() {
    // Pages live at /{version}/{slug}/ (or /{version}/ for the front page).
    const parts = location.pathname.split("/").filter(Boolean);
    return parts[0] === CURRENT_VERSION ? (parts[1] || "") : (parts[0] || "");
}

function init_version_switcher() {
    const select = document.getElementById("version-select");
    let manifest = null;

    const seed = document.createElement("option");
    seed.value = CURRENT_VERSION;
    seed.textContent = CURRENT_VERSION;
    seed.selected = true;
    select.appendChild(seed);

    fetch("/versions.json")
        .then((r) => r.ok ? r.json() : Promise.reject())
        .then((data) => {
            manifest = data;
            select.innerHTML = "";
            for (const version of data.versions) {
                const opt = document.createElement("option");
                opt.value = version;
                opt.textContent = version;
                opt.selected = version === CURRENT_VERSION;
                select.appendChild(opt);
            }
        })
        .catch(() => {});

    select.addEventListener("change", () => {
        const version = select.value;
        const slug = current_slug();
        // Only carry the current component across when the target version actually has
        // it (components map in versions.json). Otherwise land on that version's home -
        // this covers components added/removed between versions, and older versions
        // without the per-component static layout.
        const components = manifest && manifest.components ? manifest.components[version] : null;
        const canDeepLink = slug && Array.isArray(components) && components.includes(slug);
        location.href = canDeepLink ? "/" + version + "/" + slug + "/" : "/" + version + "/";
    });
}

init_theme();
init_version_switcher();

const rendered = document.getElementById("rendered");
highlight_code(rendered, "pre code");
add_copy_buttons(rendered);
start_rotating_install();
observe_fade_ins();

document.getElementById("search").addEventListener("input", (e) => filter_nav(e.target.value));
document.getElementById("hamburger").addEventListener("click", open_sidebar);
document.getElementById("overlay").addEventListener("click", close_sidebar);
document.getElementById("theme-toggle").addEventListener("click", toggle_theme);

document.addEventListener("keydown", (e) => {
    const search = document.getElementById("search");
    if (e.key === "/" && document.activeElement !== search) { e.preventDefault(); search.focus(); }
    if (e.key === "Escape") { search.value = ""; filter_nav(""); search.blur(); }
});
