import { router, usePage } from '@inertiajs/react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { switchMethod } from '@/routes/locale';

export default function LocaleSwitcher() {
    const { locale, locales } = usePage().props;

    function handleChange(value: string) {
        router.post(
            switchMethod.url(),
            { locale: value },
            { preserveScroll: true, preserveState: false },
        );
    }

    return (
        <Select value={locale} onValueChange={handleChange}>
            <SelectTrigger className="w-auto gap-2 text-xs">
                <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
                {Object.entries(locales).map(([code, name]) => (
                    <SelectItem key={code} value={code} className="text-xs">
                        {name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
