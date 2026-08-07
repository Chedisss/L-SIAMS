# Using L-SIAMS from other devices on the same network

The system already works this way — nothing needs to be added. One PC runs
L-SIAMS, and every phone, tablet and laptop on the same Wi-Fi or wired network
opens it in a browser. There is nothing to install on those devices.

This is the design: the whole system is meant to live on the school's own
network, with no internet involved.

---

## What you need

- The **host PC** — the one you run `start.bat` on. Leave it on and awake.
- Every other device on the **same Wi-Fi or the same wired network**. Guest
  Wi-Fi usually will not work; see [Troubleshooting](#troubleshooting).

---

## Step 1 — Start the system on the host PC

Double-click **`start.bat`**.

When it finishes, the window prints two addresses:

```
   L-SIAMS is running

     On this PC:        http://localhost:8080
     On other devices:  http://192.168.1.14:8080
```

The second one — `192.168.1.14` in this example — is **your** host PC's address
on the network. Yours will be different. Write it down.

If you only see the first address, the PC is not connected to the network yet.
Connect it and run `start.bat` again.

> **Finding the address yourself**
> Open Command Prompt on the host PC and run `ipconfig`. Look for
> **IPv4 Address** under your active adapter — Wi-Fi or Ethernet. It usually
> starts with `192.168.` or `10.`.

---

## Step 2 — Allow it through Windows Firewall (first time only)

The first time you run `start.bat`, Windows shows:

> **Windows Defender Firewall has blocked some features of PHP**

Tick **Private networks** and click **Allow access**. Leave *Public networks*
unticked — that setting is for cafés and airports, not the school LAN.

If you already dismissed that box, or nobody saw it, add the rule by hand.
Open Command Prompt **as Administrator** on the host PC and run these two
lines:

```bat
netsh advfirewall firewall add rule name="L-SIAMS web" dir=in action=allow protocol=TCP localport=8080
netsh advfirewall firewall add rule name="L-SIAMS realtime" dir=in action=allow protocol=TCP localport=8443
```

Both ports matter. **8080** serves the pages; **8443** carries the live updates
that make attendance appear on the dashboard as it is tapped. Without 8443 the
site still works, but the connection badge in the header stays on
"Reconnecting" and the numbers only refresh when the page is reloaded.

---

## Step 3 — Open it on the other device

On the phone, tablet or laptop, open any browser and type the address from
step 1, including the `:8080`:

```
http://192.168.1.14:8080
```

Sign in exactly as you would on the host PC. Every page, every button and every
report works the same.

That is all. Repeat step 3 on as many devices as you like.

---

## Optional: put it on the home screen

On a phone or tablet used at a classroom door, open the address, then:

- **Android (Chrome)** — ⋮ menu → *Add to Home screen*
- **iPhone / iPad (Safari)** — Share → *Add to Home Screen*

It then opens like an app, without the browser's address bar.

---

## Troubleshooting

**"This site can't be reached" on the other device**

Work through these in order:

1. **Same network?** Check the Wi-Fi name on both devices — they must match.
   Many schools have a separate *Guest* network that deliberately blocks
   devices from seeing each other; the host PC and the other devices must be on
   the same one, and not on Guest.
2. **Right address?** Re-run `ipconfig` on the host PC. A PC's address can
   change after a restart. If it changes often, ask whoever manages the network
   to reserve a fixed address for that PC — then the address never changes and
   you can print it on a card.
3. **Firewall.** Redo step 2. This is the most common cause by far.
4. **Is it running?** The `start.bat` window must still be open on the host PC.
   Closing it stops the site.
5. **Sleep.** A host PC that has gone to sleep answers nothing. Set it to never
   sleep while plugged in: *Settings → System → Power → Screen and sleep*.

**The pages load but the header says "Reconnecting"**

Port 8443 is blocked. Redo step 2 and make sure the second `netsh` line ran.
Everything else keeps working in the meantime — the page just will not update
by itself.

**"Your network address is blocked"**

The system only answers devices on private, in-school network ranges — that is
deliberate, and it is what keeps the attendance data off any wider network. The
allowed ranges are set by `TRUSTED_WEB_CIDRS` in the `.env` file, and cover the
normal ones out of the box:

```
TRUSTED_WEB_CIDRS=127.0.0.0/8,192.168.0.0/16,10.0.0.0/8
```

If your school hands out addresses starting with `172.16.` through `172.31.`,
add `172.16.0.0/12` to that line and restart. Do **not** widen it further than
your own network needs.

---

## A note on security

Everything here runs over plain `http://`, which is fine on a closed school
network and is what makes the setup this simple. It is **not** fine if the
system is ever reachable from outside the school. Do not forward port 8080 on
the router, and do not put the host PC on a public address.

If the system does need to be reachable more widely, it must be put behind
HTTPS first, with `SESSION_COOKIE_SECURE=true` and `REALTIME_TLS_ENABLED=true`
in the `.env` file. [`DEPLOYMENT.md`](DEPLOYMENT.md) covers that properly.

---

## How it works, briefly

Worth knowing if you have to explain it to someone:

- `start.bat` starts the web server bound to `0.0.0.0`, meaning "answer on
  every network connection this PC has", not just on itself.
- The web address you type is sent back to the server as the `Host` header, and
  the application builds its own links, and the live-updates address, from
  that. A device that connects to `192.168.1.14` is told to get live updates
  from `192.168.1.14` — not from `localhost`, which for a phone would mean the
  phone itself.
- That is why one setting works for every device, and why nothing in `.env`
  needs to be edited when the PC's address changes.
