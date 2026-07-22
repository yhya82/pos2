<?php

namespace App\Livewire\Categories;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class CategoryManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public ?int $editingCategoryId = null;

    public string $name = '';

    public string $description = '';

    public string $status = 'active';

    public ?int $categoryIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.categories.category-manager', [
            'categories' => Category::withCount('products')
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->paginate(10),
        ]);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('categories', 'name')->ignore($this->editingCategoryId)],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('categories', 'create');

        $this->reset(['editingCategoryId', 'name', 'description']);
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'category-form');
    }

    public function edit(int $categoryId): void
    {
        $this->authorizeAction('categories', 'update');

        $category = Category::findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->name = $category->name;
        $this->description = (string) $category->description;
        $this->status = $category->status;
        $this->resetValidation();

        $this->dispatch('open-modal', 'category-form');
    }

    public function save(): void
    {
        $isCreating = ! $this->editingCategoryId;
        $this->authorizeAction('categories', $isCreating ? 'create' : 'update');

        $validated = $this->validate();

        $previous = null;

        if ($isCreating) {
            $category = Category::create($validated);
        } else {
            $category = Category::findOrFail($this->editingCategoryId);
            $previous = $category->only(['name', 'description', 'status']);
            $category->update($validated);
        }

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'categories',
            'Category',
            $category->id,
            $previous,
            $category->only(['name', 'description', 'status']),
        );

        $this->dispatch('close-modal', 'category-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Category created.' : 'Category updated.', variant: 'success');
    }

    public function confirmDeactivate(int $categoryId): void
    {
        $this->authorizeAction('categories', 'delete');

        $this->categoryIdPendingDeactivation = $categoryId;
        $this->dispatch('open-modal', 'confirm-deactivate-category');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('categories', 'delete');

        $category = Category::findOrFail($this->categoryIdPendingDeactivation);
        $previous = $category->only(['status']);

        $category->status = $category->status === 'active' ? 'inactive' : 'active';
        $category->save();

        AuditLog::record('update', 'categories', 'Category', $category->id, $previous, $category->only(['status']));

        $this->dispatch('close-modal', 'confirm-deactivate-category');
        $this->dispatch('flash-message', message: $category->status === 'active' ? 'Category reactivated.' : 'Category deactivated.', variant: 'success');
        $this->categoryIdPendingDeactivation = null;
    }
}
