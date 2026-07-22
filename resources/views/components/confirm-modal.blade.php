@props([
    'name',
    'title' => 'Confirm',
    'message',
    'confirmLabel' => 'Confirm',
])

{{--
    SRS Sec. 20.7: no record is ever removed without an explicit
    confirmation dialog first. The actual destructive/state-changing
    action is left to the caller's own button in the $slot — this
    component only owns the dialog chrome and the standard wording, since
    what "confirm" actually does (hard delete vs. deactivate) differs by
    entity (see Sec. 20.7's own deactivate/archive guidance for records
    with historical dependencies).
--}}
<x-modal :name="$name" :show="false" max-width="sm" focusable>
    <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ $title }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ $message }}
        </p>

        <div class="mt-6 flex justify-end gap-3">
            <x-secondary-button x-on:click="$dispatch('close')">
                Cancel
            </x-secondary-button>

            {{ $slot }}
        </div>
    </div>
</x-modal>
