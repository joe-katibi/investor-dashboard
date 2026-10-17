<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} | Mobile app guide</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    {{-- Public on purpose: staff read it before their first sign-in. It describes the app only — no ticket or account data. --}}
    <style>
        :root {
            --bg: #f4f5f7; --card: #fff; --text: #1f2937; --muted: #6b7280; --line: #e5e7eb;
            --brand: #c2410c; --brand-soft: #fff7ed; --code: #f3f4f6;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111827; --card: #1f2937; --text: #e5e7eb; --muted: #9ca3af; --line: #374151;
                --brand: #fb923c; --brand-soft: #2a1d14; --code: #111827;
            }
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.55 "Segoe UI", Roboto, Arial, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 16px; }
        header { display: flex; gap: 14px; align-items: center; padding: 8px 0 16px; }
        header img { width: 56px; height: 56px; flex: none; }
        h1 { font-size: 24px; margin: 0; line-height: 1.2; }
        h2 { font-size: 20px; margin: 0 0 10px; color: var(--brand); scroll-margin-top: 12px; }
        h3 { font-size: 17px; margin: 18px 0 6px; }
        p, ul, ol { margin: 0 0 10px; }
        ul, ol { padding-left: 22px; }
        li { margin-bottom: 4px; }
        a { color: var(--brand); }
        section { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 18px; margin-bottom: 14px; overflow-wrap: anywhere; }
        .muted { color: var(--muted); }
        .lead { color: var(--muted); margin: 2px 0 0; }
        .btn { display: inline-block; padding: 0 7px; border-radius: 6px; background: var(--code); border: 1px solid var(--line); font-weight: 600; }
        .tip { background: var(--brand-soft); border-left: 4px solid var(--brand); padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
        .steps { counter-reset: step; list-style: none; padding: 0; }
        .steps > li { counter-increment: step; position: relative; padding-left: 36px; margin-bottom: 10px; }
        .steps > li::before {
            content: counter(step); position: absolute; left: 0; top: 0; width: 26px; height: 26px; border-radius: 50%;
            background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; display: flex; align-items: center; justify-content: center;
        }
        nav ol { columns: 2; column-gap: 24px; }
        @media (max-width: 520px) { nav ol { columns: 1; } }
        nav a, .top { text-decoration: none; }
        .top { display: block; text-align: right; font-size: 14px; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; font-size: 15px; }
        th, td { text-align: left; vertical-align: top; padding: 8px; border-bottom: 1px solid var(--line); }
        th { background: var(--code); }
        footer { text-align: center; font-size: 14px; padding: 8px 0 24px; }
        @media print {
            body { background: #fff; }
            section { break-inside: avoid; border-color: #ccc; }
            .top, nav { display: none; }
        }
    </style>
</head>
<body>
<main id="top">
    <header>
        <img src="{{ asset('images/oms-logo-128.png') }}" alt="">
        <div>
            <h1>OMS mobile app — user guide</h1>
            <p class="lead">The Outage Management field app for dispatchers, technicians and 4G engineers.</p>
        </div>
    </header>

    <section>
        <nav aria-label="Contents">
            <h2>Contents</h2>
            <ol>
                <li><a href="#who">Who can use the app</a></li>
                <li><a href="#sign-in">Signing in</a></li>
                <li><a href="#dashboard">Dashboard and availability</a></li>
                <li><a href="#tickets">Finding your tickets</a></li>
                <li><a href="#ticket">Working on a ticket</a></li>
                <li><a href="#technician-outage">Technician: outages</a></li>
                <li><a href="#technician-visit">Technician: site visits</a></li>
                <li><a href="#maintenance">Raising maintenance on site</a></li>
                <li><a href="#engineer">4G engineer</a></li>
                <li><a href="#dispatcher">Dispatcher</a></li>
                <li><a href="#photos">Photos and location</a></li>
                <li><a href="#notifications">Notifications</a></li>
                <li><a href="#profile">Profile and signing out</a></li>
                <li><a href="#problems">Messages and what to do</a></li>
            </ol>
        </nav>
    </section>

    <section id="who">
        <h2>1. Who can use the app</h2>
        <p>The app is for the field side of OMS. What you see depends on the role on your OMS account — the app works it out when you sign in:</p>
        <ul>
            <li><strong>Dispatcher</strong> — dispatches outages and site visits to teams and technicians, and verifies finished work.</li>
            <li><strong>Technician</strong> — works outages, site visits, installations and maintenance in the field.</li>
            <li><strong>Engineer</strong> — 4G transmission / eNodeB work.</li>
        </ul>
        <p>Other accounts (for example the NOC or Customer Experience desks) keep using the OMS website. If the app says <em>“Your account is not authorized to use the mobile application”</em>, ask your supervisor or the OMS administrator to check your role.</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="sign-in">
        <h2>2. Signing in</h2>
        <ol class="steps">
            <li>Open <strong>OMS</strong> on your phone.</li>
            <li>Enter your <strong>username or e-mail</strong> and your <strong>password</strong> — the same ones you use on the OMS website. Tap the eye icon to check what you typed.</li>
            <li>Tap <span class="btn">Sign in</span>.</li>
            <li>If your account uses two-step sign-in, an <strong>Authenticator code</strong> box appears: type the 6-digit code from your authenticator app and tap <span class="btn">Sign in</span> again.</li>
            <li>When the phone asks, <strong>allow notifications</strong> — that is how new assignments reach you. Allow the <strong>camera</strong> and <strong>location</strong> too when you first take a photo.</li>
        </ol>
        <p>You stay signed in on that phone. When the sign-in expires, the app shows <em>“Your session has ended. Please sign in again.”</em> — just sign in again.</p>
        <div class="tip">Forgot your password? Use <strong>Forgot password?</strong> on the <a href="{{ url('/admin/login') }}">OMS website sign-in page</a>, or ask the OMS administrator. Never share your password or authenticator code.</div>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="dashboard">
        <h2>3. Dashboard and availability</h2>
        <p>The app has four tabs at the bottom: <strong>Dashboard</strong>, <strong>Tickets</strong>, <strong>Notifications</strong> and <strong>Profile</strong>.</p>
        <h3>My availability</h3>
        <p>At the top of the Dashboard, choose <span class="btn">Available</span>, <span class="btn">Busy</span>, <span class="btn">On Break</span> or <span class="btn">Offline</span>.</p>
        <div class="tip"><strong>New tickets are only offered to you while you are Available.</strong> Set Available at the start of your shift, and On Break or Offline when you stop.</div>
        <h3>The tiles</h3>
        <p>Each tile shows a count; tap it to open that list of tickets. Pull down to refresh.</p>
        <table>
            <tr><th>Technicians and engineers</th><th>Dispatchers</th></tr>
            <tr>
                <td>New assignments · In progress · My tickets · Pending confirmation · Critical · Completed (30 days)</td>
                <td>Awaiting dispatch · Assigned to me · In the field · Pending confirmation · Critical · Past SLA · All open · Closed (7 days)</td>
            </tr>
        </table>
        <p class="muted"><strong>Pending confirmation</strong> means the work is reported done and is waiting for the NOC or the dispatcher to confirm it.</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="tickets">
        <h2>4. Finding your tickets</h2>
        <ul>
            <li>The <strong>Tickets</strong> tab has the same lists as the Dashboard tiles, as chips across the top — tap one to switch.</li>
            <li><strong>Search</strong> by ticket number, title, place or customer (name or account number).</li>
            <li>Tap the <strong>filter</strong> icon (top right) to show only one type of ticket or one priority, then <span class="btn">Show tickets</span>.</li>
            <li>Each card shows the ticket number, title, status, priority and the <strong>SLA</strong> chip.</li>
            <li>Pull down on the list to refresh it.</li>
        </ul>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="ticket">
        <h2>5. Working on a ticket</h2>
        <p>Tap a ticket to open it. You see its details, the <strong>Description</strong>, the <strong>Customer numbers</strong> (tap to call, or copy the number), <strong>Photos &amp; files</strong> (tap to enlarge) and the <strong>Progress</strong> history.</p>
        <h3>Accepting an assignment</h3>
        <p>When a ticket is assigned to you, a banner says so. Tap <span class="btn">Accept</span> to start. If it says <em>“Accept it by …”</em> and you do not accept in time, the ticket goes to the next available person.</p>
        <p>Cannot take it? Use <span class="btn">Reject assignment</span> and give the reason — it goes back to the queue for the next person.</p>
        <h3>The buttons at the bottom</h3>
        <ul>
            <li>The <strong>next step</strong> is shown as a large button.</li>
            <li>Everything else is under <span class="btn">More actions</span>.</li>
            <li>A greyed-out action says why it is not available yet (for example, waiting for the support technician).</li>
            <li>Each action opens a short form. Required fields must be filled. Lists of teams, technicians and reasons have a search box.</li>
        </ul>
        <div class="tip">You only ever see the actions you may take on that ticket <strong>at that moment</strong>. If you expect a button and it is not there, pull down to refresh — the ticket may have moved on, or it is with someone else.</div>
        <p><span class="btn">Add update</span> posts a progress note on the ticket for everyone following it.</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="technician-outage">
        <h2>6. Technician: outages</h2>
        <ol class="steps">
            <li><span class="btn">Accept</span> the outage.</li>
            <li><span class="btn">Site photos</span> — on Emergency and Regular outages, take the three site photos as the work goes (the findings before the repair, the rest after it). The button shows how many are done, for example <em>“Site photos (1 of 3)”</em>.</li>
            <li>Need someone for the drop cable or the customer premises? <span class="btn">Request support technician</span> — they work on the same ticket at the same time as you.</li>
            <li>Use <span class="btn">Set status</span> for situations such as Awaiting Field Access, Vendor Escalated or Partial Restore, and <span class="btn">Add update</span> to report progress.</li>
            <li>When the service is back: <span class="btn">Report restored</span> — the restoration time and details, and any site photo still missing. The NOC then confirms the restoration.</li>
        </ol>
        <p class="muted">If a support technician was requested, <em>Report restored</em> waits until they tap <span class="btn">Support work done</span> (or the request is cancelled).</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="technician-visit">
        <h2>7. Technician: site visits and installations</h2>
        <ol class="steps">
            <li><span class="btn">Accept</span> the visit.</li>
            <li><span class="btn">En route</span> when you leave — the dispatcher sees you are on the way.</li>
            <li><span class="btn">Start at customer premise</span> when you arrive. This starts the on-site timer; the photos and <em>Complete visit</em> are available from here.</li>
            <li>Installations and relocations: take the <span class="btn">Installation photos</span> as the work goes (at the FAT, along the cable, in the house). The button shows how many are done.</li>
            <li>New ONU or relocation: <span class="btn">Send to NOC for provisioning</span> (with the photo of the ONU MAC address where asked). The NOC provisions it and sends the visit back to you — then take the speed tests and finish.</li>
            <li><span class="btn">Complete visit</span> — final reason, FAT number, the required photos (geolocation photo and speed-test photo, or the remaining installation photos), work done and materials used. The visit returns to the dispatcher for the final close.</li>
        </ol>
        <h3>When things do not go to plan</h3>
        <ul>
            <li><span class="btn">Pause work</span> (with a reason), then <span class="btn">Resume work</span> later.</li>
            <li><span class="btn">Could not complete</span> — choose whether it needs an <strong>escalation</strong> (the dispatcher decides) or it is about <strong>the customer</strong> (unreachable, asked to reschedule or to be called back), and say what happened on site.</li>
            <li><span class="btn">Escalate / hand over</span> sends it to another desk; <span class="btn">Return to previous desk</span> sends it back with what you did or found.</li>
            <li>If the dispatcher finds something not right, the visit comes back to you with their reason.</li>
        </ul>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="maintenance">
        <h2>8. Raising maintenance on site</h2>
        <p>If your account may raise field maintenance, the <strong>Tickets</strong> tab has a <span class="btn">+ Raise maintenance</span> button.</p>
        <table>
            <tr><th>Corrective maintenance</th><th>Preventive maintenance</th></tr>
            <tr>
                <td>Something is broken (a fallen pole, a dropped cable). The work starts <strong>now</strong> and the ticket is assigned to you.</td>
                <td>An issue found on site that needs a <strong>planned downtime</strong>. Propose the start and end; the Dispatcher and the Infrastructure Manager approve it first.</td>
            </tr>
        </table>
        <p>Give a title, what happened, the FAT / FDT label, the location, the OLT (if known) and an estimate of customers affected, and take the <strong>before</strong> photos (outside and inside). For corrective work you can ask for a support technician on the same form.</p>
        <p>When the work is finished: <span class="btn">Report work completed</span> with what was done and the <strong>after</strong> photos.</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="engineer">
        <h2>9. 4G engineer</h2>
        <ol class="steps">
            <li><span class="btn">Accept</span> the 4G ticket.</li>
            <li><span class="btn">Start investigation</span>.</li>
            <li><span class="btn">Add photos</span> of the site and the evidence, and use <span class="btn">Set status</span> and <span class="btn">Add update</span> as you go.</li>
            <li><span class="btn">Mark resolved</span> — restoration time, root cause and resolution. The ticket returns to the 4G NOC, who verify the service and close it.</li>
        </ol>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="dispatcher">
        <h2>10. Dispatcher</h2>
        <ul>
            <li><strong>Awaiting dispatch</strong> lists what is waiting for you, the oldest SLA deadline first.</li>
            <li>Outages: <span class="btn">Dispatch</span> — pick the team; leave the technician on <em>Automatic</em> and the next eligible technician of that team gets it (FIFO), or pick one. <span class="btn">Re-dispatch</span> changes it later.</li>
            <li>Site visits: <span class="btn">Assign technician</span> (or <em>Automatic</em> for the technician queue), with an optional team and visit time. The customer is texted the technician's name and number. <span class="btn">Reschedule</span> sets the time the customer asked for.</li>
            <li>Finished visits: <span class="btn">Verify &amp; close</span>, or <span class="btn">Not done — send back</span> with what is not right. A visit past its SLA needs a reason for the delay.</li>
            <li>Preventive maintenance: <span class="btn">Approve downtime</span> or <span class="btn">Reject downtime</span>.</li>
            <li>Also, where it applies: <span class="btn">Log customer call</span>, <span class="btn">Close ticket</span>, <span class="btn">Cancel / duplicate</span>, <span class="btn">Add photos</span> and <span class="btn">Add update</span>.</li>
            <li>Keep an eye on <strong>In the field</strong>, <strong>Pending confirmation</strong>, <strong>Critical</strong> and <strong>Past SLA</strong>.</li>
        </ul>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="photos">
        <h2>11. Photos and location</h2>
        <ul>
            <li>Photos are taken with the <strong>camera only</strong> — not picked from the gallery. The time is stamped on each photo.</li>
            <li>A <strong>geolocation photo</strong> also carries your position, the place name and the time. Keep <strong>Location</strong> switched on, and take it outdoors or near a window for a good fix.</li>
            <li>Took the wrong picture? Remove it on the form and take it again before you send. A photo already on the ticket is replaced by taking it again.</li>
            <li>Forms with photos show <em>“Uploading… %”</em> — keep the app open until it finishes.</li>
        </ul>
        <div class="tip">If the camera will not open: phone <strong>Settings → Apps → OMS → Permissions</strong>, allow <strong>Camera</strong> (and <strong>Location</strong> for geolocation photos).</div>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="notifications">
        <h2>12. Notifications</h2>
        <ul>
            <li>New assignments and updates on your tickets arrive as <strong>phone notifications</strong>, even when the app is closed. Tap one to go straight to the ticket.</li>
            <li>While the app is open they appear as a <strong>banner</strong> at the bottom — tap <span class="btn">Open</span>.</li>
            <li>The <strong>Notifications</strong> tab keeps the list; the red badge is the number unread. <span class="btn">Mark all read</span> clears it.</li>
            <li>Not getting notifications? Check that notifications are allowed for OMS in the phone settings, that battery saving is not restricting OMS, and that you are signed in.</li>
        </ul>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="profile">
        <h2>13. Profile and signing out</h2>
        <p>The <strong>Profile</strong> tab shows your name, username, e-mail, phone, role, department, team, availability and the <strong>app version</strong> (useful when reporting a problem).</p>
        <p><span class="btn">Sign out</span> ends the session on this phone only. You <strong>stop receiving ticket notifications</strong> on it until you sign in again. Sign out before handing the phone to someone else.</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <section id="problems">
        <h2>14. Messages and what to do</h2>
        <table>
            <tr><th>The app says</th><th>What to do</th></tr>
            <tr><td>These credentials do not match our records.</td><td>Check the username / e-mail and password (tap the eye icon). Still failing: ask the OMS administrator — the account may be inactive.</td></tr>
            <tr><td>Your account is not authorized to use the mobile application.</td><td>Your role is not a field role. Ask your supervisor or the OMS administrator.</td></tr>
            <tr><td>Unable to connect to OMS. Please check your internet connection.</td><td>Check mobile data or Wi-Fi, then tap <span class="btn">Retry</span>.</td></tr>
            <tr><td>Internet connection required to update this ticket.</td><td>Nothing was sent. Move to better signal and send the form again.</td></tr>
            <tr><td>This action is no longer available on this ticket. Refresh and try again.</td><td>Someone else moved the ticket on. Pull down to refresh and check its latest status.</td></tr>
            <tr><td>You don't have permission to view this ticket.</td><td>The ticket is not (or no longer) yours. Ask the dispatcher if you should be on it.</td></tr>
            <tr><td>Location is switched off / blocked for OMS.</td><td>Switch Location on, or allow it in Settings → Apps → OMS → Permissions → Location, and take the photo again.</td></tr>
            <tr><td>The phone could not find its position.</td><td>Step outside or near a window and take the photo again.</td></tr>
            <tr><td>The camera could not be opened.</td><td>Allow the camera in Settings → Apps → OMS → Permissions.</td></tr>
        </table>
        <p>Anything else: tell your supervisor or the NOC the <strong>ticket number</strong>, the <strong>message</strong> you saw and your <strong>app version</strong> (Profile tab).</p>
        <a class="top" href="#top">Back to top ↑</a>
    </section>

    <footer class="muted">{{ config('app.name') }}</footer>
</main>
</body>
</html>
