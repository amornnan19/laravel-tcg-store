<?php

namespace App\Filament\Resources\BuylistSubmissions\Pages;

use App\Actions\AcceptBuylistSubmission;
use App\Actions\AssessBuylistSubmission;
use App\Actions\MarkBuylistPaid;
use App\Actions\ReceiveBuylistSubmission;
use App\Actions\ReturnBuylistSubmission;
use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Exceptions\BuylistAwaitingCustomer;
use App\Exceptions\BuylistRefused;
use App\Filament\Resources\BuylistSubmissions\BuylistSubmissionResource;
use App\Filament\Resources\BuylistSubmissions\Schemas\BuylistSubmissionForm;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use App\Support\BuylistAssessment;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * The buylist desk page for one submission. The header actions follow the status and nothing else: while the
 * submission is `revised` it offers neither accept nor pay nor an assessment, only the callout and "ส่งคืน". Saving
 * the form is the assessment: it goes through `AssessBuylistSubmission`, which takes its own locks in the project
 * order (this page runs inside the save transaction, with no lock of its own taken yet).
 */
class EditBuylistSubmission extends EditRecord
{
    protected static string $resource = BuylistSubmissionResource::class;

    /**
     * What the form showed when it was last filled or saved: a save from a page that has gone stale is refused.
     */
    #[Locked]
    public string $assessmentFingerprint = '';

    /** How the last save of this request ended: null when it did not happen, else the status it left the list in. */
    private ?BuylistStatus $savedAs = null;

    protected function getHeaderActions(): array
    {
        $actions = [
            $this->receiveAction(),
            $this->acceptAction(),
            $this->markPaidAction(),
            $this->recordTrackingAction(),
            $this->returnAction(),
        ];

        // The actions write the submission through their own locked copy; the page's record and form are filled again so
        // the buttons and fields next show the new state, not the one the page was opened with.
        return array_map(fn (Action $action): Action => $action->after(fn () => $this->refreshFromDatabase()), $actions);
    }

    protected function getFormActions(): array
    {
        if (! $this->isReceived()) {
            return [];
        }

        return [
            $this->getSaveFormAction()->label(__('admin.buylist.submissions.actions.save')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['assessments'] = $this->submissionRecord()->items->map(fn ($item): array => [
            'id' => $item->getKey(),
            'assessed_condition' => ($item->assessed_condition ?? $item->claimed_condition)->value,
            'assessed_note' => $item->assessed_note,
            'photo_path' => $item->photo_path,
        ])->all();

        return $data;
    }

    protected function afterFill(): void
    {
        $this->assessmentFingerprint = BuylistAssessment::fingerprint($this->submissionRecord());
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('items');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Gate::authorize('update', $record);

        $assessments = collect($data['assessments'] ?? [])->map(fn (array $row): array => [
            'item_id' => (int) ($row['id'] ?? 0),
            'assessed_condition' => $row['assessed_condition'] ?? null,
            'assessed_note' => $row['assessed_note'] ?? null,
            'photo_path' => $row['photo_path'] ?? null,
        ])->values();

        // The form has already written any new upload to the disk. Whatever way this ends, a file no row points at is removed
        // (after the commit on success, so a row written in this save counts).
        $uploads = $assessments->pluck('photo_path')->filter()->all();

        try {
            $assessed = app(AssessBuylistSubmission::class)->handle($this->submissionRecord(), $assessments->all(), $this->assessmentFingerprint);
        } catch (BuylistRefused $refused) {
            self::discardUnreferencedPhotos($uploads);
            Notification::make()->danger()->title($refused->message())->persistent()->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        } catch (Throwable $exception) {
            self::discardUnreferencedPhotos($uploads);

            throw $exception;
        }

        DB::afterCommit(fn () => self::discardUnreferencedPhotos($uploads));

        $this->savedAs = $assessed->status;

        return $record;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__($this->savedAs === BuylistStatus::Revised ? 'admin.buylist.submissions.done.saved_revised' : 'admin.buylist.submissions.done.saved'));
    }

    protected function afterSave(): void
    {
        $this->refreshFromDatabase();
    }

    /**
     * Deletes the stored photos that no line refers to.
     *
     * @param  list<string>  $paths
     */
    private static function discardUnreferencedPhotos(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if (! BuylistSubmissionItem::query()->where('photo_path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function isReceived(): bool
    {
        return $this->submissionRecord()->status === BuylistStatus::Received;
    }

    private function submissionRecord(): BuylistSubmission
    {
        /** @var BuylistSubmission $submission */
        $submission = $this->getRecord();

        return $submission;
    }

    private function refreshFromDatabase(): void
    {
        $this->submissionRecord()->refresh()->load('items');

        // The form's save button is cached for the request: once the list leaves `received`, reload the page so it goes.
        if (! $this->isReceived()) {
            $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->getRecord()]));

            return;
        }

        $this->fillForm();
    }

    private function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(__('admin.buylist.submissions.actions.receive'))
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->visible(fn (): bool => $this->submissionRecord()->status === BuylistStatus::Submitted)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('admin.buylist.submissions.actions.receive_heading', ['number' => $this->submissionRecord()->number]))
            ->modalDescription(fn (): string => __('admin.buylist.submissions.actions.receive_description', ['number' => $this->submissionRecord()->number]))
            ->schema(fn (): array => $this->isLate() ? [
                Textarea::make('late_note')
                    ->label(__('admin.buylist.submissions.fields.late_reason'))
                    ->required()
                    ->maxLength(255)
                    ->rows(3),
            ] : [])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->submissionRecord());

                $this->attempt(function () use ($data): void {
                    app(ReceiveBuylistSubmission::class)->handle($this->submissionRecord(), $data['late_note'] ?? null);

                    Notification::make()->success()->title(__('admin.buylist.submissions.done.receive'))->send();
                });
            });
    }

    private function acceptAction(): Action
    {
        return Action::make('accept')
            ->label(__('admin.buylist.submissions.actions.accept'))
            ->icon(Heroicon::OutlinedBanknotes)
            // From what is on the form, saved or not: a line marked down there sends the list to the customer, so nothing
            // is offered to pay.
            ->visible(fn (): bool => $this->isReceived() && $this->liveTotalSatang() >= $this->submissionRecord()->quoted_total_satang)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('admin.buylist.submissions.actions.accept_heading', ['number' => $this->submissionRecord()->number]))
            ->modalDescription(fn (): string => __($this->submissionRecord()->payout === BuylistPayout::Credit ? 'admin.buylist.submissions.actions.accept_credit' : 'admin.buylist.submissions.actions.accept_cash', [
                'amount' => Money::fromSatang($this->liveTotalSatang())->format(),
            ]))
            ->action(function (): void {
                Gate::authorize('update', $this->submissionRecord());

                // What is on the form is what gets paid: it is saved first, and a save that lowers the total sends the list
                // to the customer instead (the accept below then has nothing to do).
                $this->savedAs = null;
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                // A refused save (stale page, missing photo) has said why; nothing is accepted on unsaved lines.
                if ($this->savedAs === null) {
                    return;
                }

                if (! $this->isReceived()) {
                    Notification::make()->warning()->title(__('admin.buylist.submissions.done.saved_revised'))->persistent()->send();

                    return;
                }

                $this->attempt(function (): void {
                    $result = app(AcceptBuylistSubmission::class)->handle($this->submissionRecord(), auth()->user());

                    Notification::make()
                        ->success()
                        ->title(__($result->submission->payout === BuylistPayout::Credit ? 'admin.buylist.submissions.done.accept_credit' : 'admin.buylist.submissions.done.accept_cash'))
                        ->body($result->createdVariants > 0 ? __('admin.buylist.submissions.done.new_variants', ['n' => $result->createdVariants]) : null)
                        ->persistent($result->createdVariants > 0)
                        ->send();
                });
            });
    }

    private function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('admin.buylist.submissions.actions.mark_paid'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (): bool => $this->submissionRecord()->status === BuylistStatus::Accepted && $this->submissionRecord()->payout === BuylistPayout::Cash)
            ->modalHeading(fn (): string => __('admin.buylist.submissions.actions.mark_paid_heading', ['number' => $this->submissionRecord()->number]))
            ->modalDescription(fn (): string => __('admin.buylist.submissions.actions.mark_paid_description', ['amount' => Money::fromSatang($this->submissionRecord()->totalSatang())->format()]))
            ->schema([
                TextInput::make('reference')
                    ->label(__('admin.buylist.submissions.fields.reference'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->submissionRecord());

                $this->attempt(function () use ($data): void {
                    app(MarkBuylistPaid::class)->handle($this->submissionRecord(), (string) $data['reference'], auth()->user());

                    Notification::make()->success()->title(__('admin.buylist.submissions.done.mark_paid'))->send();
                });
            });
    }

    private function recordTrackingAction(): Action
    {
        return Action::make('recordTracking')
            ->label(__('admin.buylist.submissions.actions.record_tracking'))
            ->icon(Heroicon::OutlinedTruck)
            ->visible(fn (): bool => $this->submissionRecord()->return_tracking === null && match ($this->submissionRecord()->status) {
                BuylistStatus::Returned => true,
                // Cards the shop did not take go back with a parcel of their own.
                BuylistStatus::Accepted, BuylistStatus::Paid => BuylistAssessment::unboughtLines($this->submissionRecord())->isNotEmpty(),
                default => false,
            })
            ->modalHeading(fn (): string => __('admin.buylist.submissions.actions.record_tracking_heading', ['number' => $this->submissionRecord()->number]))
            ->schema([
                TextInput::make('tracking')
                    ->label(__('admin.buylist.submissions.fields.tracking'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->submissionRecord());

                $this->attempt(function () use ($data): void {
                    app(ReturnBuylistSubmission::class)->handle($this->submissionRecord(), (string) $data['tracking']);

                    Notification::make()->success()->title(__('admin.buylist.submissions.done.tracking'))->send();
                });
            });
    }

    private function returnAction(): Action
    {
        return Action::make('return')
            ->label(__('admin.buylist.submissions.actions.return'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (): bool => in_array($this->submissionRecord()->status, [BuylistStatus::Submitted, BuylistStatus::Received, BuylistStatus::Revised], true))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('admin.buylist.submissions.actions.return_heading', ['number' => $this->submissionRecord()->number]))
            ->modalDescription(__('admin.buylist.submissions.actions.return_description'))
            ->schema([
                TextInput::make('tracking')
                    ->label(__('admin.buylist.submissions.fields.tracking'))
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->submissionRecord());

                $this->attempt(function () use ($data): void {
                    app(ReturnBuylistSubmission::class)->handle($this->submissionRecord(), $data['tracking'] ?? null);

                    Notification::make()->success()->title(__('admin.buylist.submissions.done.return'))->send();
                });
            });
    }

    /**
     * The total the form would give if it were saved now: the same sum as the form's own "ยอดใหม่" line.
     */
    private function liveTotalSatang(): int
    {
        return BuylistSubmissionForm::newTotal($this->submissionRecord(), $this->data['assessments'] ?? []);
    }

    private function isLate(): bool
    {
        return now()->gt($this->submissionRecord()->quote_expires_at);
    }

    /**
     * Runs an action and shows a refusal as a notice that stays, instead of an error page.
     *
     * @param  Closure(): void  $work
     */
    private function attempt(Closure $work): void
    {
        try {
            $work();
        } catch (BuylistRefused $refused) {
            Notification::make()->danger()->title($refused->message())->persistent()->send();
        } catch (BuylistAwaitingCustomer) {
            Notification::make()->danger()->title(__('admin.buylist.submissions.awaiting_customer'))->body(__('admin.buylist.submissions.awaiting_customer_hint'))->persistent()->send();
        }
    }
}
