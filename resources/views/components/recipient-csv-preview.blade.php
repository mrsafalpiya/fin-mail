@php
    /** @var \FinityLabs\FinMail\Helpers\RecipientCsvResult $result */
    $tokens = $result->mappedTokens;
    $rows = array_slice($result->rows, 0, 50);
    $placeholder = __('fin-mail::fin-mail.compose.csv.preview.empty_value');
@endphp

<div class="fi-modal-content space-y-4">
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="whitespace-nowrap px-3 py-2 text-start font-medium text-gray-950 dark:text-white">
                        {{ __('fin-mail::fin-mail.compose.csv.preview.email') }}
                    </th>

                    @foreach ($tokens as $token)
                        <th class="whitespace-nowrap px-3 py-2 text-start font-medium text-gray-950 dark:text-white">
                            {{ $token }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="whitespace-nowrap px-3 py-2 text-gray-950 dark:text-white">
                            {{ $row['email'] }}
                        </td>

                        @foreach ($tokens as $token)
                            <td @class([
                                'px-3 py-2',
                                'text-gray-950 dark:text-white' => filled($row['tokens'][$token] ?? null),
                                'text-gray-400 dark:text-gray-500' => blank($row['tokens'][$token] ?? null),
                            ])>
                                {{ $row['tokens'][$token] ?? $placeholder }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (count($result->rows) > count($rows))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('fin-mail::fin-mail.compose.csv.preview.truncated', [
                'shown' => count($rows),
                'total' => count($result->rows),
            ]) }}
        </p>
    @endif
</div>
