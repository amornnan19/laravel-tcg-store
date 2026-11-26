<?php

namespace App\Livewire\Storefront;

use App\Actions\RespondToBuylistRevision;
use App\Enums\BuylistStatus;
use App\Exceptions\BuylistRefused;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The box on `/buylist/{number}` while the shop's re-inspection came out lower than the customer's claim: what was found,
 * with the photos, and the two answers. Mounted by the owner's page only; the action checks owner and status again under
 * the submission's lock.
 */
class BuylistRevisionBox extends Component
{
    #[Locked]
    public int $submissionId;

    public ?string $error = null;

    public function mount(BuylistSubmission $submission): void
    {
        $this->submissionId = $submission->getKey();
    }

    public function confirmNewTotal(): void
    {
        $this->respond(true);
    }

    public function askForCardsBack(): void
    {
        $this->respond(false);
    }

    public function render(): View
    {
        $current = BuylistSubmission::query()->with('items.printing')->findOrFail($this->submissionId);

        return view('livewire.storefront.buylist-revision-box', [
            'submission' => $current,
            'changed' => $this->changedLines($current),
        ]);
    }

    private function respond(bool $accept): void
    {
        $this->error = null;

        $submission = BuylistSubmission::query()->findOrFail($this->submissionId);
        abort_unless($submission->user_id === auth()->id(), 404);

        try {
            app(RespondToBuylistRevision::class)->handle($submission, auth()->user(), $accept);
        } catch (BuylistRefused $refused) {
            $this->error = __('storefront.buylist.revision.refused.'.$refused->reason);

            return;
        }

        // The page around the box shows the new status, so it is loaded again.
        $this->redirectRoute('buylist.show', $submission->number);
    }

    /**
     * @return Collection<int, BuylistSubmissionItem>
     */
    private function changedLines(BuylistSubmission $submission): Collection
    {
        if ($submission->status !== BuylistStatus::Revised) {
            return collect();
        }

        return $submission->items->filter(fn (BuylistSubmissionItem $item): bool => $item->assessed_condition !== null && $item->assessed_condition !== $item->claimed_condition);
    }
}
