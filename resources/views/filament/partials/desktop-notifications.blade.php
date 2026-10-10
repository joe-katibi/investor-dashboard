@php
    $omsUser = \Filament\Facades\Filament::auth()->user();
@endphp

{{-- Desktop notifications: a prompt to switch them on, and the listener that shows them. Signed-in pages only. --}}
@if ($omsUser)
    <div
        id="oms-desktop"
        data-user-id="{{ $omsUser->getAuthIdentifier() }}"
        {{-- Changes with every sign-in, so "Remind me later" lasts until the person signs in again. --}}
        data-sign-in="{{ substr(sha1((string) session()->getId()), 0, 12) }}"
        data-poll-url="{{ route('desktop-alerts') }}"
        data-poll-seconds="40"
        data-icon="{{ asset('android-chrome-192x192.png') }}"
        hidden
    >
        <div class="oms-desktop-card" role="dialog" aria-labelledby="oms-desktop-title">
            <div class="oms-desktop-head">
                <span class="oms-desktop-bell" aria-hidden="true">🔔</span>
                <strong id="oms-desktop-title">Desktop notifications</strong>
            </div>
            <p data-oms-desktop="text">
                Get an alert on your screen when a ticket is assigned to you, escalated or about to breach its SLA — also when OMS is in
                another tab or you are working in another program.
            </p>
            <p data-oms-desktop="status" role="status" aria-live="polite" class="oms-desktop-status"></p>
            <div class="oms-desktop-actions">
                <button type="button" data-oms-desktop="enable" class="oms-desktop-primary">Enable notifications</button>
                <button type="button" data-oms-desktop="later">Remind me later</button>
            </div>
            <button type="button" data-oms-desktop="never" class="oms-desktop-link">Don’t ask again on this computer</button>
        </div>
    </div>

    <style>
        #oms-desktop { position: fixed; top: 76px; right: 20px; z-index: 60; width: min(380px, calc(100vw - 32px)); }
        .oms-desktop-card { background: #fff; color: #1f2937; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; box-shadow: 0 12px 36px rgba(0, 0, 0, .22); font-size: 14px; line-height: 1.5; }
        .dark .oms-desktop-card { background: #18181b; color: #e4e4e7; border-color: #3f3f46; }
        .oms-desktop-head { display: flex; align-items: center; gap: 8px; font-size: 15px; margin-bottom: 6px; }
        .oms-desktop-bell { font-size: 20px; }
        .oms-desktop-status { color: #b45309; margin-top: 6px; }
        .oms-desktop-status:empty { display: none; }
        .oms-desktop-actions { display: flex; gap: 8px; margin-top: 12px; }
        .oms-desktop-actions button { flex: 1; border: 0; border-radius: 8px; padding: 9px 10px; cursor: pointer; font-weight: 600; background: #f3f4f6; color: #374151; }
        .dark .oms-desktop-actions button { background: #27272a; color: #e4e4e7; }
        .oms-desktop-actions button.oms-desktop-primary { background: #d97706; color: #fff; }
        .oms-desktop-actions button:disabled { opacity: .6; cursor: default; }
        .oms-desktop-link { background: none; border: 0; padding: 0; margin-top: 10px; font-size: 12px; color: #6b7280; cursor: pointer; text-decoration: underline; }
    </style>

    <script data-navigate-once>
        (() => {
            if (window.omsDesktop) {
                return;
            }

            const root = () => document.getElementById('oms-desktop');
            const part = (name) => root()?.querySelector(`[data-oms-desktop="${name}"]`);
            const supported = () => 'Notification' in window && window.isSecureContext;
            const store = (kind) => { try { return window[kind]; } catch (error) { return null; } };
            const read = (kind, key) => { try { return store(kind)?.getItem(key) ?? null; } catch (error) { return null; } };
            const write = (kind, key, value) => { try { store(kind)?.setItem(key, value); } catch (error) { /* private mode: nothing to keep */ } };
            const userId = () => root()?.dataset.userId;
            const key = (name) => `oms-desktop-${name}-${userId()}`;
            // "Later" and "already asked" belong to one sign-in: signing in again starts afresh.
            const once = (name) => `${key(name)}-${root()?.dataset.signIn}`;

            // ---------------------------------------------------------------- the prompt

            const NOT_SECURE = 'This address is not secure (http://). Browsers only let a site ask for desktop notifications on an https:// page — '
                + 'open OMS with its https:// address and click Enable again. Until then alerts appear in the bell and on the page.';
            const BLOCKED = 'Notifications are blocked for OMS in this browser. Click the padlock (or the icon left of the address), set Notifications to “Allow”, then reload the page.';

            function refreshPrompt() {
                const prompt = root();

                // A browser without desktop notifications at all: nothing to offer.
                if (!prompt || !('Notification' in window)) {
                    return;
                }

                const dismissed = read('sessionStorage', once('later')) === '1' || read('localStorage', key('never')) === '1';

                if ((supported() && Notification.permission === 'granted') || dismissed) {
                    prompt.hidden = true;
                    return;
                }

                // Blocked earlier: the browser will not ask again by itself, so say how to unblock it.
                part('status').textContent = supported() && Notification.permission === 'denied' ? BLOCKED : '';
                prompt.hidden = false;
            }

            /** Ask the browser for permission. $byClick: the person pressed Enable (otherwise it is the automatic attempt after sign-in). */
            async function enable(byClick = true) {
                const status = part('status');
                const button = part('enable');

                if (!window.isSecureContext) {
                    if (byClick) { status.textContent = NOT_SECURE; }

                    return;
                }

                if (Notification.permission === 'denied') {
                    if (byClick) { status.textContent = BLOCKED; }

                    return;
                }

                if (byClick) {
                    button.disabled = true;
                    status.textContent = 'Choose “Allow” in the question your browser is showing near the address bar…';
                }

                try {
                    const permission = await Notification.requestPermission();

                    if (permission === 'granted') {
                        new Notification('OMS notifications are on', { body: 'You will be alerted here when a ticket needs you.', icon: root()?.dataset.icon, tag: 'oms-enabled' });
                    }
                } catch (error) {
                    if (byClick) { status.textContent = 'The browser did not allow the question. Check its notification settings.'; }
                } finally {
                    button.disabled = false;
                    refreshPrompt();
                }
            }

            /**
             * Right after sign-in the browser's own "Allow notifications?" question is brought up without
             * waiting for a click — once per sign-in. Chrome, Edge and Brave show it; Firefox and Safari
             * only ask after a click, so there the card's Enable button is what brings it up.
             */
            function askOnce() {
                if (!supported() || Notification.permission !== 'default' || read('sessionStorage', once('asked')) === '1'
                    || read('sessionStorage', once('later')) === '1' || read('localStorage', key('never')) === '1') {
                    return;
                }

                write('sessionStorage', once('asked'), '1');
                enable(false);
            }

            document.addEventListener('click', (event) => {
                const action = event.target.closest?.('[data-oms-desktop]')?.dataset.omsDesktop;

                if (action === 'enable') {
                    enable(true);
                } else if (action === 'later') {
                    write('sessionStorage', once('later'), '1');
                    refreshPrompt();
                } else if (action === 'never') {
                    write('localStorage', key('never'), '1');
                    refreshPrompt();
                }
            });

            // ---------------------------------------------------------------- showing an alert

            // Every open OMS tab hears about a notification; the list of what was shown is shared, so it appears once.
            function alreadyShown(id) {
                let shown = [];
                try { shown = JSON.parse(read('localStorage', key('shown')) || '[]'); } catch (error) { shown = []; }

                if (shown.includes(id)) {
                    return true;
                }

                shown.push(id);
                write('localStorage', key('shown'), JSON.stringify(shown.slice(-150)));

                return false;
            }

            function open(url) {
                if (!url) {
                    return;
                }

                const target = new URL(url, window.location.origin);

                // Only ever a page of OMS itself.
                if (target.origin === window.location.origin) {
                    window.location.assign(target.href);
                }
            }

            /** True when this page is the one that showed it (not shown before, here or in another tab). */
            function show(alert, live) {
                if (!alert?.id || alreadyShown(alert.id)) {
                    return false;
                }

                present(alert, live);

                return true;
            }

            function present(alert, live) {
                const looking = document.visibilityState === 'visible' && document.hasFocus();

                if (looking) {
                    // The person is on OMS: the live channel already shows its own message on the page.
                    // Without it (found by asking the server), show one here.
                    if (!live && window.FilamentNotification) {
                        new window.FilamentNotification().title(alert.title).body(alert.body).icon('heroicon-o-bell-alert')
                            .iconColor(alert.severity === 'critical' ? 'danger' : 'primary').duration(8000).send();
                    }

                    return;
                }

                if (!supported() || Notification.permission !== 'granted') {
                    return;
                }

                const notification = new Notification(alert.title, {
                    body: alert.body,
                    icon: root()?.dataset.icon,
                    tag: alert.id,
                    requireInteraction: alert.severity === 'critical',
                });

                notification.onclick = () => {
                    window.focus();
                    open(alert.url);
                    notification.close();
                };
            }

            // ---------------------------------------------------------------- hearing about one

            let subscribedFor = null;

            function listen() {
                const id = userId();

                if (!id || !window.Echo || subscribedFor === id) {
                    return;
                }

                subscribedFor = id;
                window.Echo.private(`App.Models.User.${id}`).listen('.desktop.alert', (alert) => {
                    liveProven = true;
                    show(alert, true);
                });
            }

            // With thousands of people signed in, every page asking every 40 seconds is a lot of requests for
            // nothing. Once the live connection has delivered an alert here, asking becomes a safety net
            // (every few minutes); if asking ever finds something the live connection missed, it is back to normal.
            const QUIET_SECONDS = 300;
            let liveProven = false;
            let lastAsked = 0;
            const connected = () => window.Echo?.connector?.pusher?.connection?.state === 'connected';

            function tick() {
                if (liveProven && connected() && Date.now() - lastAsked < QUIET_SECONDS * 1000) {
                    return;
                }

                ask();
            }

            let since = new Date().toISOString();
            let asking = false;
            let stopped = false;

            async function ask() {
                const url = root()?.dataset.pollUrl;

                if (!url || asking || stopped) {
                    return;
                }

                asking = true;
                lastAsked = Date.now();

                try {
                    const response = await fetch(`${url}?after=${encodeURIComponent(since)}`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });

                    if (response.status === 401 || response.status === 419 || response.status === 403) {
                        stopped = true; // signed out: nothing more to ask until the next page load
                    } else if (response.ok) {
                        const answer = await response.json();
                        since = answer.now || since;
                        (answer.alerts || []).forEach((alert) => {
                            if (show(alert, false)) {
                                liveProven = false; // the live connection did not bring this one
                            }
                        });
                    }
                } catch (error) {
                    // offline for a moment: the next round asks again
                } finally {
                    asking = false;
                }
            }

            function start() {
                refreshPrompt();
                askOnce();
                listen();
            }

            window.omsDesktop = { refresh: start, show };
            window.addEventListener('EchoLoaded', listen);
            document.addEventListener('livewire:navigated', start);
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { ask(); } });
            setInterval(tick, Math.max(15, Number(root()?.dataset.pollSeconds || 40)) * 1000);

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', start, { once: true });
            } else {
                start();
            }
        })();
    </script>
@endif
