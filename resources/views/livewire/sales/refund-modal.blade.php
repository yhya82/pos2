<div>
    <x-modal name="refund-sale" max-width="2xl">
        <div class="dialog">
            <div class="dh">
                <h2>Refund sale</h2>
                @if ($sale)
                    <p>
                        {{ $sale->receipt_number }} — {{ $sale->sale_date->format('Y-m-d h:i A') }}
                        @if ($sale->customer) &middot; {{ $sale->customer->name }} @endif
                    </p>
                @endif
            </div>

            @if ($error)
                <div class="db">
                    <p class="error">{{ $error }}</p>
                </div>
                <div class="df">
                    <button type="button" class="btn" x-on:click="$dispatch('close')">Close</button>
                </div>
            @elseif ($sale)
                <form wire:submit="submit">
                    <div class="db">
                        @foreach ($returnLines as $index => $line)
                            <div class="refline" wire:key="refund-line-{{ $index }}">
                                <div class="refline-top">
                                    <span>{{ $line['product_name'] }}</span>
                                    <span class="mut">
                                        {{ rtrim(rtrim($line['max_returnable'], '0'), '.') }} eligible &commat; {{ number_format($line['unit_price'], 2) }}
                                        @if ($line['already_returned'] > 0)
                                            &middot; <span class="alreadyret">{{ rtrim(rtrim($line['already_returned'], '0'), '.') }} already returned</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="refline-grid">
                                    <div>
                                        <label>Qty to return</label>
                                        <input type="text" wire:model="returnLines.{{ $index }}.quantity">
                                    </div>
                                    <div>
                                        <label>Condition</label>
                                        <select wire:model="returnLines.{{ $index }}.condition_type">
                                            <option value="sellable">Sellable</option>
                                            <option value="damaged">Damaged</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div class="f">
                            <label for="refund_overall_reason">Overall reason</label>
                            <textarea wire:model="overallReason" id="refund_overall_reason" rows="2" placeholder="Why this return is being processed"></textarea>
                            <x-input-error :messages="$errors->get('overallReason')" class="mt-2" />
                        </div>
                    </div>

                    <div class="df">
                        <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                        <button type="submit" class="btn p">Process return</button>
                    </div>
                </form>
            @endif
        </div>
    </x-modal>
</div>
