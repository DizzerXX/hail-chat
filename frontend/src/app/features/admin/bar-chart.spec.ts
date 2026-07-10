import { TestBed } from '@angular/core/testing';
import { BarChart, BarDatum } from './bar-chart';

const data: BarDatum[] = [
  { date: '2026-07-01', count: 2 },
  { date: '2026-07-02', count: 0 },
  { date: '2026-07-03', count: 5 },
];

describe('BarChart', () => {
  it('scales bars to the maximum and anchors them to the baseline', async () => {
    await TestBed.configureTestingModule({ imports: [BarChart] }).compileComponents();

    const fixture = TestBed.createComponent(BarChart);
    fixture.componentRef.setInput('data', data);
    await fixture.whenStable();

    const component = fixture.componentInstance as unknown as {
      bars: () => { height: number; count: number }[];
      peak: () => { count: number } | null;
      max: () => number;
    };

    const bars = component.bars();
    expect(bars).toHaveLength(3);
    expect(component.max()).toBe(5);

    const tallest = bars[2];
    const zero = bars[1];
    expect(tallest.height).toBeGreaterThan(0);
    expect(zero.height).toBe(0);
    expect(component.peak()?.count).toBe(5);

    const paths = (fixture.nativeElement as HTMLElement).querySelectorAll('path.bar');
    expect(paths).toHaveLength(3);
  });

  it('renders the accessible table mirror', async () => {
    await TestBed.configureTestingModule({ imports: [BarChart] }).compileComponents();

    const fixture = TestBed.createComponent(BarChart);
    fixture.componentRef.setInput('data', data);
    await fixture.whenStable();

    const rows = (fixture.nativeElement as HTMLElement).querySelectorAll('table.sr-only tbody tr');
    expect(rows).toHaveLength(3);
  });
});
