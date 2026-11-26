<?php

namespace App\Filament\Resources\BuylistSubmissions\Tables;

use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Filament\Resources\BuylistSubmissions\BuylistSubmissionResource;
use App\Models\BuylistSubmission;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BuylistSubmissionsTable
{
    /** The statuses that still need something from the shop or the customer. */
    public const array OPEN_STATUSES = ['submitted', 'received', 'revised', 'accepted'];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.buylist.submissions.fields.number'))
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label(__('admin.buylist.submissions.fields.customer'))
                    ->description(fn (BuylistSubmission $submission): ?string => $submission->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('payout')
                    ->label(__('admin.buylist.submissions.fields.payout'))
                    ->badge()
                    ->formatStateUsing(fn (BuylistPayout $state): string => $state->label())
                    ->color(fn (BuylistPayout $state): string => $state === BuylistPayout::Credit ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->label(__('admin.buylist.submissions.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (BuylistStatus $state): string => $state->staffLabel())
                    ->color(fn (BuylistStatus $state): string => match ($state->color()) {
                        'low' => 'warning',
                        'info' => 'info',
                        'stock' => 'success',
                        default => 'danger',
                    }),
                TextColumn::make('quoted_total_satang')
                    ->label(__('admin.buylist.submissions.fields.quoted'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->alignEnd(),
                TextColumn::make('final_total_satang')
                    ->label(__('admin.buylist.submissions.fields.final'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('quote_expires_at')
                    ->label(__('admin.buylist.submissions.fields.quote_expires_at'))
                    ->dateTime('j M Y', config('shop.timezone')),
                TextColumn::make('created_at')
                    ->label(__('admin.buylist.submissions.fields.submitted_at'))
                    ->dateTime('j M Y H:i', config('shop.timezone')),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            // Oldest first: the list is a queue, and the one waiting longest is the one to open.
            ->defaultSort('id', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.buylist.submissions.filters.status'))
                    ->multiple()
                    ->options(collect(BuylistStatus::cases())->reject(fn (BuylistStatus $status): bool => $status === BuylistStatus::Draft)->mapWithKeys(fn (BuylistStatus $status): array => [$status->value => $status->staffLabel()])->all())
                    ->default(self::OPEN_STATUSES),
            ])
            ->recordUrl(fn (BuylistSubmission $record): string => BuylistSubmissionResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('admin.buylist.submissions.empty'));
    }
}
