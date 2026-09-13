const { session, admin, expect, output, fs } = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  try {
    for (const width of [1280, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await admin(page, "history");
      const chart = page.locator(".sspa-history-chart");
      await expect(chart.locator("canvas")).toBeVisible();
      await chart.scrollIntoViewIfNeeded();
      const boxes = await chart.evaluate((m) =>
        m.sspaChart
          .getZr()
          .storage.getDisplayList()
          .filter(
            (e) =>
              e.style &&
              [
                "Previous measurements",
                "Recent measurements",
                "Request wall time (ms)",
                "Errors",
              ].includes(e.style.text),
          )
          .map((e) => {
            const b = e.getBoundingRect().clone();
            b.applyTransform(e.getComputedTransform());
            return {
              text: e.style.text,
              x: b.x,
              y: b.y,
              width: b.width,
              height: b.height,
            };
          }),
      );
      fs.writeFileSync(
        output + "/history-labels-" + width + ".json",
        JSON.stringify(boxes, null, 2),
      );
      await chart.screenshot({
        path: output + "/history-labels-" + width + ".png",
      });
      expect(boxes).toHaveLength(4);
      for (let i = 0; i < boxes.length; i++)
        for (let j = i + 1; j < boxes.length; j++) {
          const a = boxes[i],
            b = boxes[j];
          const overlap =
            Math.min(a.x + a.width, b.x + b.width) > Math.max(a.x, b.x) &&
            Math.min(a.y + a.height, b.y + b.height) > Math.max(a.y, b.y);
          expect(
            overlap,
            width + "px: " + a.text + " must not overlap " + b.text,
          ).toBe(false);
        }
      console.log(
        "PASS " + width + "px actual History axis/legend labels do not overlap",
      );
    }
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
