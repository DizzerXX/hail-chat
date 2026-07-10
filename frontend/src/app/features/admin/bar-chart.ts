import { Component, computed, input, signal } from '@angular/core';

export interface BarDatum {
  date: string;
  count: number;
}

interface Bar extends BarDatum {
  x: number;
  y: number;
  width: number;
  height: number;
  label: string;
}

const WIDTH = 900;
const HEIGHT = 260;
const PAD = { top: 18, right: 8, bottom: 26, left: 36 };

/**
 * Single-series daily bar chart. Series hue #2a78d6 (categorical slot 1)
 * validated against the light surface; marks use 4px rounded data-ends,
 * >=2px gaps, hairline grid, and a per-bar hover tooltip. A visually
 * hidden table mirrors the data for screen readers.
 */
@Component({
  selector: 'app-bar-chart',
  template: `
    <div class="viz-root">
      @if (hovered(); as bar) {
        <div
          class="tooltip"
          [style.left.%]="((bar.x + bar.width / 2) / svgWidth) * 100"
        >
          <strong>{{ bar.count }}</strong> conversation{{ bar.count === 1 ? '' : 's' }}
          <span class="muted">· {{ bar.label }}</span>
        </div>
      }

      <svg viewBox="0 0 ${WIDTH} ${HEIGHT}" role="img" [attr.aria-label]="ariaLabel()">
        <!-- hairline grid + y ticks -->
        @for (tick of ticks(); track tick.value) {
          <line x1="${PAD.left}" [attr.y1]="tick.y" x2="${WIDTH - PAD.right}" [attr.y2]="tick.y" class="grid" />
          <text x="${PAD.left - 6}" [attr.y]="tick.y + 3" class="tick" text-anchor="end">
            {{ tick.value }}
          </text>
        }

        <!-- baseline -->
        <line
          x1="${PAD.left}"
          [attr.y1]="baselineY"
          x2="${WIDTH - PAD.right}"
          [attr.y2]="baselineY"
          class="baseline"
        />

        <!-- bars: rounded at the data end only, anchored to the baseline -->
        @for (bar of bars(); track bar.date) {
          <path [attr.d]="barPath(bar)" class="bar" />
        }

        <!-- selective direct label: the peak day only -->
        @if (peak(); as bar) {
          <text
            [attr.x]="bar.x + bar.width / 2"
            [attr.y]="bar.y - 5"
            class="peak-label"
            text-anchor="middle"
          >
            {{ bar.count }}
          </text>
        }

        <!-- x labels: first, last, and sparse in-betweens -->
        @for (bar of xLabels(); track bar.date) {
          <text
            [attr.x]="bar.x + bar.width / 2"
            y="${HEIGHT - 8}"
            class="tick"
            text-anchor="middle"
          >
            {{ bar.label }}
          </text>
        }

        <!-- hover hit targets: full column height, wider than the mark -->
        @for (bar of bars(); track bar.date) {
          <rect
            [attr.x]="bar.x - 1"
            y="${PAD.top}"
            [attr.width]="bar.width + 2"
            height="${HEIGHT - PAD.top - PAD.bottom}"
            class="hit"
            (mouseenter)="hovered.set(bar)"
            (mouseleave)="hovered.set(null)"
          />
        }
      </svg>

      <table class="sr-only">
        <caption>Conversations per day</caption>
        <tbody>
          @for (datum of data(); track datum.date) {
            <tr>
              <th scope="row">{{ datum.date }}</th>
              <td>{{ datum.count }}</td>
            </tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .viz-root {
      /* palette roles — light values validated against the light surface */
      --series-1: #2a78d6;
      --grid: #e1e0d9;
      --baseline: #c3c2b7;
      --ink-muted: #898781;
      --ink-primary: #0b0b0b;
      position: relative;
    }
    @media (prefers-color-scheme: dark) {
      .viz-root {
        --series-1: #3987e5;
        --grid: #2c2c2a;
        --baseline: #383835;
        --ink-muted: #898781;
        --ink-primary: #ffffff;
      }
    }
    svg {
      width: 100%;
      height: auto;
      display: block;
    }
    .bar {
      fill: var(--series-1);
    }
    .grid {
      stroke: var(--grid);
      stroke-width: 1;
    }
    .baseline {
      stroke: var(--baseline);
      stroke-width: 1;
    }
    .tick {
      fill: var(--ink-muted);
      font-size: 11px;
    }
    .peak-label {
      fill: var(--ink-primary);
      font-size: 11px;
      font-weight: 600;
    }
    .hit {
      fill: transparent;
      cursor: default;
    }
    .tooltip {
      position: absolute;
      top: -6px;
      transform: translateX(-50%);
      background: var(--mat-sys-inverse-surface);
      color: var(--mat-sys-inverse-on-surface);
      border-radius: 6px;
      padding: 4px 10px;
      font-size: 12px;
      white-space: nowrap;
      pointer-events: none;
      z-index: 1;
    }
    .tooltip .muted {
      opacity: 0.75;
    }
    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
    }
  `,
})
export class BarChart {
  readonly data = input.required<BarDatum[]>();

  protected readonly svgWidth = WIDTH;
  protected readonly baselineY = HEIGHT - PAD.bottom;
  protected readonly hovered = signal<Bar | null>(null);

  protected readonly max = computed(() =>
    Math.max(1, ...this.data().map((datum) => datum.count)),
  );

  protected readonly bars = computed<Bar[]>(() => {
    const data = this.data();
    if (data.length === 0) {
      return [];
    }

    const plotWidth = WIDTH - PAD.left - PAD.right;
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const step = plotWidth / data.length;
    const gap = Math.max(2, step * 0.25);
    const width = Math.max(2, step - gap);

    return data.map((datum, index) => {
      const height = Math.round((datum.count / this.max()) * plotHeight);
      return {
        ...datum,
        x: PAD.left + index * step + gap / 2,
        y: this.baselineY - height,
        width,
        height,
        label: this.shortDate(datum.date),
      };
    });
  });

  protected readonly ticks = computed(() => {
    const max = this.max();
    const step = Math.max(1, Math.ceil(max / 3));
    const values = [step, step * 2, step * 3].filter((value) => value <= max * 1.05);
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;

    return values.map((value) => ({
      value,
      y: this.baselineY - (value / max) * plotHeight,
    }));
  });

  protected readonly peak = computed<Bar | null>(() => {
    const bars = this.bars().filter((bar) => bar.count > 0);
    return bars.length === 0
      ? null
      : bars.reduce((best, bar) => (bar.count > best.count ? bar : best));
  });

  protected readonly xLabels = computed<Bar[]>(() => {
    const bars = this.bars();
    const every = Math.max(1, Math.ceil(bars.length / 8));
    return bars.filter(
      (_bar, index) => index === 0 || index === bars.length - 1 || index % every === 0,
    );
  });

  protected readonly ariaLabel = computed(
    () => `Bar chart of conversations per day, peak ${this.max()}`,
  );

  protected barPath(bar: Bar): string {
    const radius = Math.min(4, bar.width / 2, bar.height);
    const { x, y, width } = bar;

    return [
      `M ${x} ${this.baselineY}`,
      `L ${x} ${y + radius}`,
      `Q ${x} ${y} ${x + radius} ${y}`,
      `L ${x + width - radius} ${y}`,
      `Q ${x + width} ${y} ${x + width} ${y + radius}`,
      `L ${x + width} ${this.baselineY}`,
      'Z',
    ].join(' ');
  }

  private shortDate(date: string): string {
    return date.slice(5); // YYYY-MM-DD → MM-DD
  }
}
