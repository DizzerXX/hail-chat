import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { FaqGroup } from '../../core/api-types';
import { FaqDialog } from './faq-dialog';

interface FaqDialogInternals {
  forms: Record<
    'ms' | 'en',
    { enabled: boolean; question: string; answer: string; keywords: string[] }
  >;
  valid: () => boolean;
  addKeyword: (language: 'ms' | 'en', value: string) => void;
  removeKeyword: (language: 'ms' | 'en', value: string) => void;
}

const group: FaqGroup = {
  translation_group: 'g-1',
  translations: {
    ms: {
      id: 1,
      question: 'Soalan BM?',
      answer: 'Jawapan BM.',
      keywords: ['kata laluan'],
      is_active: true,
      usage_count: 3,
    },
    en: null,
  },
  usage_count: 3,
  updated_at: '2026-07-09T00:00:00Z',
};

async function makeDialog(data: FaqGroup | null): Promise<FaqDialogInternals> {
  await TestBed.configureTestingModule({
    imports: [FaqDialog],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideAnimationsAsync(),
      { provide: MatDialogRef, useValue: { close: () => undefined } },
      { provide: MAT_DIALOG_DATA, useValue: data },
    ],
  }).compileComponents();

  const fixture = TestBed.createComponent(FaqDialog);
  await fixture.whenStable();

  return fixture.componentInstance as unknown as FaqDialogInternals;
}

describe('FaqDialog', () => {
  it('prefills forms from an existing group and marks missing languages disabled', async () => {
    const dialog = await makeDialog(group);

    expect(dialog.forms.ms.enabled).toBe(true);
    expect(dialog.forms.ms.question).toBe('Soalan BM?');
    expect(dialog.forms.en.enabled).toBe(false);
    expect(dialog.valid()).toBe(true);
  });

  it('requires question and answer for every enabled language', async () => {
    const dialog = await makeDialog(null);

    // New FAQ defaults to BM enabled but empty.
    expect(dialog.forms.ms.enabled).toBe(true);
    expect(dialog.valid()).toBe(false);

    dialog.forms.ms.question = 'Bagaimana?';
    dialog.forms.ms.answer = 'Begini.';
    expect(dialog.valid()).toBe(true);

    dialog.forms.en.enabled = true;
    expect(dialog.valid()).toBe(false);
  });

  it('normalizes keywords and prevents duplicates', async () => {
    const dialog = await makeDialog(null);

    dialog.addKeyword('ms', '  Kata Laluan ');
    dialog.addKeyword('ms', 'kata laluan');
    expect(dialog.forms.ms.keywords).toEqual(['kata laluan']);

    dialog.removeKeyword('ms', 'kata laluan');
    expect(dialog.forms.ms.keywords).toEqual([]);
  });
});
