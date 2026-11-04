import { Component, input } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { CustomField } from '../../core/api-types';

/**
 * The custom pre-chat question rows, shared by the global Chat Settings page
 * and the per-inbox override in the inbox dialog. Edits the array in place, so
 * the owning screen keeps control of loading and saving.
 */
@Component({
  selector: 'app-prechat-fields-editor',
  imports: [
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    @for (f of fields(); track $index) {
      <div class="custom-row">
        <mat-form-field appearance="outline" subscriptSizing="dynamic">
          <mat-label>Key</mat-label>
          <input matInput [(ngModel)]="f.key" placeholder="order_no" />
        </mat-form-field>
        <mat-form-field appearance="outline" subscriptSizing="dynamic">
          <mat-label>Label</mat-label>
          <input matInput [(ngModel)]="f.label" />
        </mat-form-field>
        <mat-form-field appearance="outline" subscriptSizing="dynamic" class="type">
          <mat-label>Type</mat-label>
          <mat-select [(ngModel)]="f.type">
            @for (t of fieldTypes; track t) {
              <mat-option [value]="t">{{ t }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        @if (f.type === 'select') {
          <mat-form-field appearance="outline" subscriptSizing="dynamic">
            <mat-label>Options (comma-separated)</mat-label>
            <input
              matInput
              [ngModel]="(f.options || []).join(', ')"
              (ngModelChange)="setOptions(f, $event)"
            />
          </mat-form-field>
        }
        @if (f.type === 'cascade') {
          <mat-form-field appearance="outline" subscriptSizing="dynamic" class="tree">
            <mat-label>Tree JSON — [{{ '{' }}key,label,children?{{ '}' }}]</mat-label>
            <textarea
              matInput
              rows="4"
              [ngModel]="treeText(f)"
              (ngModelChange)="onTreeInput(f, $event)"
            ></textarea>
          </mat-form-field>
        }
        <mat-slide-toggle [(ngModel)]="f.required">Required</mat-slide-toggle>
        <button matIconButton (click)="remove($index)" aria-label="Remove field">
          <mat-icon>delete</mat-icon>
        </button>
      </div>
    }
    <button matButton (click)="add()"><mat-icon>add</mat-icon> Add custom field</button>
  `,
  styles: `
    .custom-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 8px;

      .type {
        width: 130px;
      }
      .tree {
        width: 100%;
      }
    }
  `,
})
export class PrechatFieldsEditor {
  readonly fields = input.required<CustomField[]>();

  protected readonly fieldTypes = [
    'text',
    'email',
    'phone',
    'number',
    'select',
    'cascade',
  ] as const;

  /** Raw JSON text per cascade field while editing (kept out of the payload). */
  private readonly treeDrafts = new Map<CustomField, string>();

  protected treeText(field: CustomField): string {
    return this.treeDrafts.get(field) ?? JSON.stringify(field.tree ?? [], null, 2);
  }

  protected onTreeInput(field: CustomField, text: string): void {
    this.treeDrafts.set(field, text);
    try {
      const parsed = JSON.parse(text);
      if (Array.isArray(parsed)) {
        field.tree = parsed;
      }
    } catch {
      // Keep the last valid tree; the server validates the structure on save.
    }
  }

  protected setOptions(field: CustomField, text: string): void {
    field.options = text
      .split(',')
      .map((option) => option.trim())
      .filter((option) => option !== '');
  }

  protected add(): void {
    this.fields().push({ key: '', label: '', type: 'text', required: false });
  }

  protected remove(index: number): void {
    this.fields().splice(index, 1);
  }
}
