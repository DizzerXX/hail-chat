import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { firstValueFrom } from 'rxjs';
import { FaqGroup } from '../../core/api-types';
import { AdminApiService, FaqGroupPayload } from './admin-api.service';

interface TranslationForm {
  enabled: boolean;
  question: string;
  answer: string;
  keywords: string[];
  isActive: boolean;
}

const LANGUAGES = [
  { code: 'ms' as const, label: 'Bahasa Malaysia' },
  { code: 'en' as const, label: 'English' },
];

@Component({
  selector: 'app-faq-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatChipsModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSlideToggleModule,
    MatTabsModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ group ? 'Edit FAQ' : 'New FAQ' }}</h2>
    <mat-dialog-content>
      <mat-tab-group>
        @for (language of languages; track language.code) {
          <mat-tab>
            <ng-template mat-tab-label>
              {{ language.label }}
              @if (!forms[language.code].enabled) {
                <mat-icon class="missing" aria-label="No version in this language">visibility_off</mat-icon>
              }
            </ng-template>

            <div class="dialog-form tab-body">
              <mat-slide-toggle [(ngModel)]="forms[language.code].enabled">
                Provide a {{ language.label }} version
              </mat-slide-toggle>

              @if (forms[language.code].enabled) {
                <mat-form-field appearance="outline">
                  <mat-label>Question</mat-label>
                  <input matInput [(ngModel)]="forms[language.code].question" required maxlength="500" />
                </mat-form-field>

                <mat-form-field appearance="outline">
                  <mat-label>Answer</mat-label>
                  <textarea matInput rows="5" [(ngModel)]="forms[language.code].answer" required></textarea>
                </mat-form-field>

                <mat-form-field appearance="outline">
                  <mat-label>Match keywords</mat-label>
                  <mat-chip-grid #grid>
                    @for (keyword of forms[language.code].keywords; track keyword) {
                      <mat-chip-row (removed)="removeKeyword(language.code, keyword)">
                        {{ keyword }}
                        <button matChipRemove aria-label="Remove keyword">
                          <mat-icon>cancel</mat-icon>
                        </button>
                      </mat-chip-row>
                    }
                  </mat-chip-grid>
                  <input
                    [matChipInputFor]="grid"
                    (matChipInputTokenEnd)="addKeyword(language.code, $event.value); $event.chipInput.clear()"
                    placeholder="Type and press Enter"
                  />
                  <mat-hint>The bot matches these words in visitor messages</mat-hint>
                </mat-form-field>

                <mat-slide-toggle [(ngModel)]="forms[language.code].isActive">Active</mat-slide-toggle>
              }
            </div>
          </mat-tab>
        }
      </mat-tab-group>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!valid()" (click)="save()">Save</button>
    </mat-dialog-actions>
  `,
  styles: `
    .tab-body {
      padding-top: 16px;
      min-width: 520px;
    }
    .missing {
      margin-left: 6px;
      font-size: 16px;
      height: 16px;
      width: 16px;
      opacity: 0.6;
    }
  `,
})
export class FaqDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<FaqDialog, boolean>);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly group = inject<FaqGroup | null>(MAT_DIALOG_DATA);

  protected readonly languages = LANGUAGES;

  protected readonly forms: Record<'ms' | 'en', TranslationForm> = {
    ms: this.toForm('ms'),
    en: this.toForm('en'),
  };

  protected readonly busy = signal(false);

  protected addKeyword(language: 'ms' | 'en', value: string): void {
    const keyword = value.trim().toLowerCase();
    if (keyword !== '' && !this.forms[language].keywords.includes(keyword)) {
      this.forms[language].keywords = [...this.forms[language].keywords, keyword];
    }
  }

  protected removeKeyword(language: 'ms' | 'en', keyword: string): void {
    this.forms[language].keywords = this.forms[language].keywords.filter(
      (entry) => entry !== keyword,
    );
  }

  protected valid(): boolean {
    const enabled = LANGUAGES.filter((language) => this.forms[language.code].enabled);

    return (
      enabled.length > 0 &&
      enabled.every(
        (language) =>
          this.forms[language.code].question.trim() !== '' &&
          this.forms[language.code].answer.trim() !== '',
      )
    );
  }

  protected async save(): Promise<void> {
    this.busy.set(true);

    const payload: FaqGroupPayload = { translations: {} };

    for (const language of LANGUAGES) {
      const form = this.forms[language.code];

      if (form.enabled) {
        payload.translations[language.code] = {
          question: form.question.trim(),
          answer: form.answer.trim(),
          keywords: form.keywords,
          is_active: form.isActive,
        };
      } else if (this.group?.translations[language.code]) {
        // Explicit removal of an existing language version.
        payload.translations[language.code] = null;
      }
    }

    try {
      await firstValueFrom(this.api.saveFaqGroup(payload, this.group?.translation_group));
      this.ref.close(true);
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Save failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    } finally {
      this.busy.set(false);
    }
  }

  private toForm(language: 'ms' | 'en'): TranslationForm {
    const translation = this.group?.translations[language] ?? null;

    return {
      enabled: this.group === null ? language === 'ms' : translation !== null,
      question: translation?.question ?? '',
      answer: translation?.answer ?? '',
      keywords: translation?.keywords ?? [],
      isActive: translation?.is_active ?? true,
    };
  }
}
