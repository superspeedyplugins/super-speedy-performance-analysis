# Update and save analysis

Profiles the administrator's real Update or Save as the write request itself.

## What you get to see

The save you just pressed, measured: request phases, per-plugin cost, SQL, outbound HTTP, mail, peak RAM and, with excimer installed, the functions by self time. The same diagnostics as any other profile, on a request that normally leaves no trace.

It works on existing post, page, product, custom post type and WooCommerce HPOS order edit screens.

## Why you can't see this any other way

WordPress saves over ajax or REST and then reloads the editor. A tool watching the page render sees the editor screen loading afterwards, not the write. So a `save_post` callback that takes twenty seconds hides in a request nothing was watching, and the symptom the shop owner reports is "saving a product is slow" with nothing to point at.

This profiles the write request itself, and the editor page that loads afterwards is explicitly excluded. A slow `save_post` callback can't hide inside an ordinary edit-screen page profile.

## It's the real save

Not a temporary duplicate, not a dry run. Your edits are saved. Normal mail, webhooks and integrations run unchanged and are measured, which is the point: a save is slow because of what it sets off.

Two transports are handled. The classic editor's authenticated `post.php` form POST, and the block editor's authenticated REST update. Classic form tokens are removed from both `$_POST` and `$_REQUEST` before ordinary plugins load, and REST tokens travel in the signed profiling header, so no plugin sees a stray token in the request it's handling.

The result is stored as its own `admin_save` run with the real request method, separate from your site-wide numbers.

## How to run it

Open a post, page, product, CPT item or HPOS order for editing, make your edits, then use **Analyse update/save** in the PA admin-bar menu instead of the Update button.

The control only appears on those screens. From the shared Super Speedy dashboard, "Analyse a post or product save" opens the most recent published item with the tool primed.

## How to read the result

Start with the phase table. A slow save is nearly always one of three things, and the phases separate them: plugin boot callbacks (something expensive loads on every admin request), the write itself (`save_post` and friends), or outbound HTTP during the save (a sync to an external service running inline instead of in the background).

Mail time is broken out. A save that sends notification email is paying for your mail server inside the request.

## Requirements and limits

An existing item. There's no measurement for creating a brand new one, because the profiled request has to be a real save of real edits.

Administrator only, on your own site, same-origin.

---

Related: [[Per-Page-Analysis]] · [[Plugin-Impact-Analysis]] · [[How-It-Measures]]
