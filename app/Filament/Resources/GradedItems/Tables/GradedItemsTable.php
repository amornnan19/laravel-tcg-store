<?php

namespace App\Filament\Resources\GradedItems\Tables;

use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Models\GradedItem;
use App\Support\Money;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GradedItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('printingWithTrashed.cardWithTrashed.name_en')
                    ->label(__('admin.fields.card'))
                    ->searchable(),
                TextColumn::make('code')
                    ->label(__('admin.fields.number'))
                    ->state(fn (GradedItem $record): string => $record->printingWithTrashed->set->code.' '.$record->printingWithTrashed->number),
                TextColumn::make('grade_badge')
                    ->label(__('admin.fields.grade'))
                    ->state(fn (GradedItem $record): string => $record->gradeBadge())
                    ->badge(),
                TextColumn::make('price_satang')
                    ->label(__('admin.fields.price'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('admin.fields.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['printingWithTrashed.set', 'printingWithTrashed.cardWithTrashed']))
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(GradedStatus::class),
                SelectFilter::make('grader')
                    ->label(__('admin.fields.grader'))
                    ->options(Grader::class),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::withdrawAction(),
                ]),
            ]);
    }

    /**
     * Takes the selected items off the storefront. A sold item is skipped, as it would stop a cancel or a refund from
     * putting it back, and so is a reserved one: a customer holds it, and its state belongs to the hold.
     */
    public static function withdrawAction(): BulkAction
    {
        return BulkAction::make('withdraw')
            ->label(__('admin.bulk.withdraw.label'))
            ->icon(Heroicon::OutlinedEyeSlash)
            ->requiresConfirmation()
            ->modalHeading(__('admin.bulk.withdraw.heading'))
            ->modalDescription(__('admin.bulk.withdraw.description'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, BulkAction $action): void {
                $count = GradedItem::query()
                    ->whereKey($records->modelKeys())
                    ->whereNotIn('status', [GradedStatus::Sold->value, GradedStatus::Reserved->value])
                    ->update(['status' => GradedStatus::Withdrawn->value]);

                $action->successNotificationTitle(__('admin.bulk.withdraw.done', ['count' => $count]));
                $action->success();
            });
    }
}
