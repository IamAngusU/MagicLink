(() => {
  "use strict";

  const exchange = document.querySelector("[data-exchange]");
  if (exchange) {
    window.addEventListener("hashchange", () => {
      if (location.hash) location.reload();
    });
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
      const confirmationPanel = exchange.querySelector("[data-exchange-confirmation]");
      const confirmationButton = exchange.querySelector("[data-exchange-confirm]");
      let confirmation = "";
      const submit = async () => {
        confirmationButton.disabled = true;
        try {
          const response = await fetch(exchange.dataset.endpoint, {
            method: "POST",
            credentials: "same-origin",
            headers: {
              "Content-Type": "application/json",
              "X-CSRF-Token": exchange.dataset.csrf,
            },
            body: JSON.stringify({ id: exchange.dataset.selector, token, confirmation }),
          });
          const payload = await response.json();
          if (!response.ok || !payload.ok) throw new Error(payload.error?.message || "Link rejected");
          message.textContent = payload.data.message;
          if (payload.code === "magic_link.confirmation_required") {
            exchange.querySelector("[data-exchange-title]").textContent = document.documentElement.lang === "de" ? "Anmeldung bestätigen." : "Confirm your sign-in.";
            confirmation = payload.data.confirmation;
            exchange.querySelector("[data-exchange-identity]").textContent = payload.data.email;
            exchange.querySelector("[data-exchange-warning]").textContent = document.documentElement.lang === "de"
              ? (payload.data.account_switch ? "Du wechselst zu einem anderen Konto. Bestätige nur, wenn du das möchtest." : "Dieser Browser hat den Link nicht angefordert. Melde dich nur mit deiner eigenen E-Mail-Adresse an.")
              : (payload.data.account_switch ? "You are switching accounts. Confirm only if you intend to do this." : "This browser did not request the link. Continue only with your own email address.");
            confirmationPanel.hidden = false;
            exchange.dataset.awaitingConfirmation = "true";
            confirmationButton.disabled = false;
            confirmationButton.focus();
            return;
          }
          confirmationPanel.hidden = true;
          delete exchange.dataset.awaitingConfirmation;
          exchange.dataset.confirmed = "true";
          setTimeout(() => location.replace(payload.data.redirect), 360);
        } catch (error) {
          confirmationPanel.hidden = true;
          delete exchange.dataset.awaitingConfirmation;
          message.textContent = error.message;
          exchange.dataset.failed = "true";
        }
      };
      confirmationButton.addEventListener("click", () => {
        if (confirmation && !confirmationButton.disabled) submit();
      });
      submit();
    }
  }

  const waiting = document.querySelector("[data-waiting]");
  if (waiting) {
    const message = waiting.querySelector("[data-state-message]");
    const stateCode = waiting.querySelector("[data-state-code]");
    const countdown = waiting.querySelector("[data-countdown]");
    let pollAfter = Math.max(500, Number(waiting.dataset.pollAfter) || 2500);
    let timer = 0;
    let stopped = false;

    const tick = () => {
      const remaining = Number(countdown.dataset.expiresAt) * 1000 - Date.now();
      if (remaining <= 0) return;
      const minutes = Math.floor(remaining / 60000);
      const seconds = Math.floor((remaining % 60000) / 1000);
      countdown.textContent = `${minutes}:${String(seconds).padStart(2, "0")}`;
    };

    const poll = async () => {
      if (stopped) return;
      clearTimeout(timer);
      try {
        const response = await fetch(waiting.dataset.stateUrl, {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          if (response.status === 429) {
            pollAfter = Math.max(pollAfter, Number(payload.meta?.poll_after_ms) || 0);
          } else {
            throw new Error(payload.error?.message || "State unavailable");
          }
        } else {
          message.textContent = payload.data.message;
          stateCode.textContent = payload.data.state;
          pollAfter = Math.max(500, Number(payload.meta?.poll_after_ms) || pollAfter);
          if (payload.data.terminal) {
            stopped = true;
            waiting.dataset.state = payload.data.state;
            if (payload.data.verified) waiting.dataset.confirmed = "true";
            return;
          }
        }
      } catch (_) {
        // A short network interruption must not destroy the waiting state.
      }
      if (!stopped) {
        timer = setTimeout(poll, document.hidden ? Math.max(5000, pollAfter) : pollAfter);
      }
    };

    tick();
    const clock = setInterval(() => {
      tick();
      if (Number(countdown.dataset.expiresAt) * 1000 <= Date.now()) {
        clearInterval(clock);
        if (!stopped) {
          stopped = true;
          stateCode.textContent = "expired";
        }
      }
    }, 1000);
    timer = setTimeout(poll, Math.min(600, pollAfter));
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden && !stopped) {
        clearTimeout(timer);
        timer = setTimeout(poll, 100);
      }
    });
  }
})();
