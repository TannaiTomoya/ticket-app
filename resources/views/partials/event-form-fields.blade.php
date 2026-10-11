<flux:input wire:model="form.title" label="タイトル" type="text" required maxlength="100" />

<flux:textarea wire:model="form.description" label="説明" rows="6" required />

<flux:input wire:model="form.venue" label="会場" type="text" required maxlength="100" />

<div class="grid gap-6 sm:grid-cols-2">
    <flux:input wire:model="form.starts_at" label="開始日時" type="datetime-local" required />
    <flux:input wire:model="form.ends_at" label="終了日時" type="datetime-local" required />
</div>
