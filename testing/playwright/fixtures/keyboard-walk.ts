/**
 * Keyboard-walk helper — tabs through every focusable element on a page
 * and records each stop for assertion.
 *
 * Usage:
 *   import { walkFocusableElements } from '../fixtures/keyboard-walk';
 *   const stops = await walkFocusableElements(page);
 *   for (const stop of stops) expect(stop.hasRing).toBe(true);
 */
import { type Page } from '@playwright/test';

export interface FocusStop {
  index: number;
  tagName: string;
  selector: string;
  text: string;
  hasRing: boolean;
  boundingBox: { x: number; y: number; width: number; height: number } | null;
}

export async function walkFocusableElements(
  page: Page,
  maxStops = 100,
): Promise<FocusStop[]> {
  const stops: FocusStop[] = [];
  let firstKey: string | null = null;

  for (let i = 0; i < maxStops; i++) {
    await page.keyboard.press('Tab');
    await page.waitForTimeout(50);

    const stop = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;

      const tag = el.tagName.toLowerCase();
      const id = el.id ? `#${el.id}` : '';
      const cls = el.className
        ? `.${String(el.className).split(/\s+/).filter(Boolean).join('.')}`
        : '';
      const selector = `${tag}${id}${cls}`;

      const cs = window.getComputedStyle(el);
      const outline = cs.outlineStyle;
      const shadow = cs.boxShadow;
      const hasRing =
        (outline !== 'none' && outline !== '') ||
        (shadow !== 'none' && shadow !== '');

      const rect = el.getBoundingClientRect();
      const text = (el.textContent || '').trim().substring(0, 60);

      return {
        tagName: tag,
        selector,
        text,
        hasRing,
        boundingBox: {
          x: Math.round(rect.x),
          y: Math.round(rect.y),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
        },
      };
    });

    if (!stop) break;

    const key = stop.selector + stop.text;
    if (firstKey === null) {
      firstKey = key;
    } else if (key === firstKey) {
      break;
    }

    stops.push({ index: i, ...stop });
  }

  return stops;
}
