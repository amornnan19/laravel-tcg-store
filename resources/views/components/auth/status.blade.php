@if (session('status'))
    <p role="status" class="rounded-control border border-stock/40 bg-stock/10 px-4 py-3 text-small font-medium text-stock">{{ session('status') }}</p>
@endif
@if (session('status_error'))
    <div role="alert" class="flex items-start gap-3 rounded-card border border-line-strong bg-surface p-4">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-danger"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v4.6M10 13.4v.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        <p class="min-w-0 flex-1 text-small font-medium text-danger">{{ session('status_error') }}</p>
    </div>
@endif
