# Function-Level Profiling

Most performance tools stop at "this plugin is slow". Function-level profiling answers the next question: which exact function inside it, called on whose behalf, during which part of the request. Super Speedy Performance Analysis gets there with the `excimer` PHP extension - a sampling profiler built by the Wikimedia Foundation and run on every Wikipedia page view - and once the extension is installed, every profile the plugin takes gains the function view automatically. There is nothing to switch on.

:::callout{variant="did-you-know"}
Excimer samples the complete call stack once per millisecond and does nothing in between, so profiled requests run at native speed. That is why it can profile during the normal measurement pass without distorting the very numbers being measured - the overhead is typically under one percent.
:::

## What you get

With excimer present, the "Analyse this page" panel and the Pages tab drill-down gain:

- **A "By function" table**, self time first: the functions that actually burned the CPU, each with self and inclusive milliseconds, its source file, and the plugin or theme it belongs to.
- **"Driven by" splits**: when a shared function's time is spent on behalf of several plugins - the object cache is the classic case - the row shows the split, so "the cache is busy" becomes "this plugin is keeping it busy".
- **Per-phase function lists**: the untimed part of each request phase expands to the functions the profiler sampled during that phase - click the render remainder and see what was really running during render, including the theme's own template code, which no hook-based instrument can reach.

Attribution uses the same shared-library-aware stack walk as the plugin's SQL analysis: because every sample carries its complete call stack, a plugin is never blamed for a vendor library another plugin called into.

## Reading the numbers

The two columns answer different questions, and mixing them up leads to the wrong culprit:

- **Self time** is milliseconds spent in the function's own code. This is where CPU actually went - the column to optimise from.
- **Inclusive time** is self time plus everything called underneath. Dispatch machinery scores enormous inclusive numbers with tiny self numbers: `WP_Hook::apply_filters` can show hundreds of milliseconds inclusive while costing a few milliseconds itself, because nearly all WordPress work happens inside hooks.

:::callout{variant="mistake"}
A huge inclusive number on <code>do_action</code> or <code>apply_filters</code> does not mean "hooks are slow" - it means "most of the page happens inside hooks", which is true of every WordPress site. Look at SELF time for culprits; inclusive time is for understanding structure.
:::

Sampling is statistical: a function cheaper than the sample period is invisible, and figures are approximations (milliseconds = samples that caught the function). The approximation is honest at page scale - the sampled wall time typically agrees with the independently measured generation time to within a few percent, so the two instruments cross-check each other.

## Installing excimer

The Tools tab does the thinking for you: it detects your operating system, PHP version and init system, and generates the exact commands for your server - or a ready-to-paste message for your host, which on shared hosting is the route that actually works. The shape of it:

```bash
sudo pecl install excimer
echo "extension=excimer.so" | sudo tee /etc/php/8.3/fpm/conf.d/20-excimer.ini
sudo systemctl restart php8.3-fpm
php -m | grep excimer
```

:::callout{variant="performance-tip"}
If <code>pecl install</code> fails with "shtool ... does not exist or is not executable", your server mounts /tmp with noexec - a common hardening default. Temporarily allow execution for the build, then restore it: <code>sudo mount -o remount,exec /tmp</code>, install, then <code>sudo mount -o remount,noexec /tmp</code>.
:::

After the PHP restart, press Re-check on the Tools tab: the excimer card switches to Active, and the next analysis carries the function view. The plugin never installs anything itself - it shows the commands, you or your host run them.

:::callout{variant="result"}
Validation from a live store: 459 samples across a 685ms shop page, with the sampled wall time agreeing with the measured generation time to within a few percent - and the function view naming a 69ms geolocation file scan that no query- or hook-level instrument could see.
:::

## When sampling is not enough

Exact call counts - proving a function ran 47 times rather than "was busy" - need a tracing profiler, which costs enough overhead to distort measurements. Sampling answers most questions without that cost, which is why it is the default and the tracing mode is a planned deep-dive addition rather than the everyday instrument.

One measurement subtlety worth knowing: profiling requests are loopbacks from your server to itself, and on some hosts those bypass your CDN - the panel states which path each measurement took, and the Geolocation Speed Boosts guide covers why that matters.

## Further reading

- [Installing the fastest WordPress stack on Ubuntu 24.04 LTS](https://www.superspeedyplugins.com/kb/performance-optimization/stack-guides-tips/installing-the-fastest-wordpress-stack-ubuntu-24-04/) - a server stack where installing PHP extensions like excimer is routine rather than a support ticket.
