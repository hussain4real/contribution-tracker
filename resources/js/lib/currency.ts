import type { AppPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface CurrencyFormatOptions {
    minimumFractionDigits?: number;
    maximumFractionDigits?: number;
}

const ISO_CURRENCY_PATTERN = /^[A-Z]{3}$/;

export function normalizeCurrency(currency?: string | null): string {
    const normalized = currency?.trim();

    return normalized && normalized.length > 0 ? normalized : '₦';
}

export function formatCurrencyAmount(
    amount: number | null | undefined,
    currency?: string | null,
    options: CurrencyFormatOptions = {},
): string {
    const normalizedCurrency = normalizeCurrency(currency);
    const value = Number(amount ?? 0);
    const minimumFractionDigits =
        options.minimumFractionDigits ?? options.maximumFractionDigits ?? 2;
    const maximumFractionDigits =
        options.maximumFractionDigits ?? minimumFractionDigits;
    const fractionOptions = {
        minimumFractionDigits,
        maximumFractionDigits,
    };

    if (ISO_CURRENCY_PATTERN.test(normalizedCurrency)) {
        return new Intl.NumberFormat('en', {
            style: 'currency',
            currency: normalizedCurrency,
            currencyDisplay: 'narrowSymbol',
            ...fractionOptions,
        }).format(value);
    }

    return `${normalizedCurrency}${value.toLocaleString('en', fractionOptions)}`;
}

export function useCurrencyFormatter(
    defaultOptions: CurrencyFormatOptions = {},
) {
    const page = usePage<AppPageProps>();
    const currency = computed(() =>
        normalizeCurrency(page.props.family?.currency),
    );

    function formatCurrency(
        amount: number | null | undefined,
        options: CurrencyFormatOptions = {},
    ): string {
        return formatCurrencyAmount(amount, currency.value, {
            ...defaultOptions,
            ...options,
        });
    }

    return {
        currency,
        formatCurrency,
    };
}
