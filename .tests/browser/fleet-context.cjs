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
const target = process.env.SSPA_TEST_SITE_DIR;
const site = process.env.SSPA_TEST_SITE_URL;
if (
  !target ||
  !site ||
  path.resolve(target) !==
    path.join(
      nativeRoot,
      "super-speedy-performance-analysis",
      "tests-feature-regressions",
    ) ||
  path.basename(target) !== "tests-feature-regressions" ||
  path.basename(path.dirname(target)) !== "super-speedy-performance-analysis" ||
  fs.realpathSync(
    path.join(target, "wp-content/plugins/super-speedy-performance-analysis"),
  ) !== root
)
  throw Error(
    "Matching dedicated native target and invoking plugin checkout are required",
  );
if (
  new URL(site).hostname !==
  "tests-feature-regressions.super-speedy-performance-analysis.localhost"
)
  throw Error("Unexpected native regression hostname");
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
