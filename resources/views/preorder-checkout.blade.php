<x-layouts.storefront :title="__('storefront.preorder.checkout.title')" :results="true" :bottom-nav="false">
    <div class="mx-auto max-w-3xl px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <livewire:storefront.preorder-checkout :preorder-id="$preorder->id" :qty="$qty" :plan="$plan" />
    </div>
</x-layouts.storefront>
