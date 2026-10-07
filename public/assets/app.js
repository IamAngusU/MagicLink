(() => {
  "use strict";

  const exchange = document.querySelector("[data-exchange]");
  if (exchange) {
    const message = exchange.querySelector("[data-exchange-message]");
    const params = new URLSearchParams(location.hash.slice(1));
    const token = params.get("token") || "";
    history.replaceState(null, "", location.pathname + location.search);

    if (!token) {
      message.textContent = document.documentElement.lang === "de"
        ? "Der Link ist unvollständig. Fordere einen neuen an."
        : "The link is incomplete. Request a new one.";
      exchange.dataset.failed = "true";
    } else {
      fetch(exchange.dataset.endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": exchange.dataset.csrf,
        },
        body: JSON.stringify({ id: exchange.dataset.selector, token }),
      })
        .then(async (response) => {
          const payload = await response.json();
          if (!response.ok || !payload.ok) throw new Error(payload.message || "Link rejected");
          message.textContent = payload.message;
          exchange.dataset.confirmed = "true";
          setTimeout(() => location.replace(payload.redirect), 360);
        })
        .catch((error) => {
          message.textContent = error.message;
          exchange.dataset.failed = "true";
        });
    }
  }

  const waiting = document.querySelector("[data-waiting]");
  if (waiting) {
    const message = waiting.querySelector("[data-state-message]");
    const countdown = waiting.querySelector("[data-countdown]");
    let timer = 0;

    const tick = () => {
      const remaining = Number(countdown.dataset.expiresAt) * 1000 - Date.now();
      if (remaining <= 0) return;
      const minutes = Math.floor(remaining / 60000);
      const seconds = Math.floor((remaining % 60000) / 1000);
      countdown.textContent = `${minutes}:${String(seconds).padStart(2, "0")}`;
    };

    const poll = async () => {
      clearTimeout(timer);
      try {
        const response = await fetch(waiting.dataset.stateUrl, {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        const payload = await response.json();
        message.textContent = payload.message;
        if (payload.verified && payload.redirect) {
          waiting.dataset.confirmed = "true";
          location.replace(payload.redirect);
          return;
        }
      } catch (_) {
        // A short network interruption must not destroy the waiting state.
      }
      timer = setTimeout(poll, document.hidden ? 5000 : 1400);
    };

    tick();
    setInterval(tick, 1000);
    poll();
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) poll();
    });
  }
})();
