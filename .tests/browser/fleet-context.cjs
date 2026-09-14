const fs = require("node:fs");
const path = require("node:path");
const { execFileSync } = require("node:child_process");
const { chromium, expect } = require("@playwright/test");
const root = path.resolve(__dirname, "../..");
const workspace =
  process.env.SUPERSPEEDY_WORKSPACE ||
  path.join(require("node:os").homedir(), "dev/super-speedy");
const nativeRoot = execFileSync(
  "bash",
  [
    "-c",
    'source "$1"; printf "%s" "$SITES_ROOT"',
    "native-root",
    path.join(workspace, "tools/parallel-dev/bin/lib.sh"),
  ],
  { encoding: "utf8" },
).trim();
// The site is whichever scenario env.sh resolved (SSPA_SCENARIO, default
// tests-feature-regressions). Three guards stay: it must be an isolated parallel-dev site for
// this plugin, its plugin symlink must resolve to the checkout running the test, and its
// hostname must match. Each failure names what was found, so a wrong site reads as a wrong
// site rather than as a plugin defect.
const target = process.env.SSPA_TEST_SITE_DIR;
const site = process.env.SSPA_TEST_SITE_URL;
if (!target || !site)
  throw Error(
    "SSPA_TEST_SITE_DIR and SSPA_TEST_SITE_URL are required; source .tests/env.sh first",
  );
const scenario = path.basename(target);
const expectedDir = path.join(
  nativeRoot,
  "super-speedy-performance-analysis",
  scenario,
);
if (path.resolve(target) !== expectedDir)
  throw Error(
    `Refusing non-isolated site ${target}; expected a parallel-dev site under ${expectedDir}`,
  );
const linked = fs.realpathSync(
  path.join(target, "wp-content/plugins/super-speedy-performance-analysis"),
);
if (linked !== root)
  throw Error(
    `Site ${scenario} loads the plugin from ${linked}, not this checkout ${root}`,
  );
const expectedHost = `${scenario}.super-speedy-performance-analysis.localhost`;
if (new URL(site).hostname !== expectedHost)
  throw Error(
    `Unexpected hostname ${new URL(site).hostname}; expected ${expectedHost}`,
  );
const wp = (...args) =>
  execFileSync(
    process.env.SSPA_TEST_REAL_WP || "wp",
    [...args, `--path=${target}`, `--url=${site}`],
    { encoding: "utf8", maxBuffer: 32e6 },
  ).trim();
const json = (code) => JSON.parse(wp("eval", code));
const output = process.env.SSPA_FLEET_OUTPUT;
if (!output) throw Error("SSPA_FLEET_OUTPUT is required for retained evidence");
const state = (id) =>
  json(`echo wp_json_encode(SSPA_Run_Controller::status(${Number(id)}));`);
async function login(
  context,
  user = process.env.SSPA_E2E_USER,
  password = process.env.SSPA_E2E_PASSWORD,
) {
  const page = await context.newPage();
  page.on("console", (message) =>
    fs.appendFileSync(
      output + "/console.log",
      message.type() + ": " + message.text() + "\n",
    ),
  );
  page.on("requestfailed", (request) =>
    fs.appendFileSync(
      output + "/request-failures.log",
      request.method() +
        " " +
        request.url() +
        " " +
        request.failure()?.errorText +
        "\n",
    ),
  );
  await page.goto(site + "/wp-login.php");
  await expect(page.locator("#user_login")).toBeFocused();
  await page.locator("#user_login").fill(user);
  await page.locator("#user_pass").fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes("wp-login.php")),
    page.locator("#wp-submit").click(),
  ]);
  return page;
}
async function session() {
  const browser = await chromium.launch();
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    reducedMotion: "reduce",
    permissions: ["clipboard-read", "clipboard-write"],
  });
  return { browser, context, page: await login(context) };
}
async function admin(page, tab = "overview") {
  await page.goto(site + "/wp-admin/admin.php?page=sspa#" + tab);
}
const response = (page, action) =>
  page.waitForResponse(
    (r) =>
      r.url().includes("admin-ajax.php") &&
      (r.request().postData() || "").includes("action=" + action),
  );
module.exports = {
  fs,
  path,
  root,
  target,
  site,
  wp,
  json,
  output,
  state,
  login,
  session,
  admin,
  response,
  expect,
};
