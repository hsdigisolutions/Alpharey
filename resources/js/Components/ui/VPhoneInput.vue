<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const COUNTRIES = [
    // Iberian Peninsula
    { dial: '+34',  flag: '🇪🇸', name: 'España' },
    { dial: '+351', flag: '🇵🇹', name: 'Portugal' },
    // Western Europe
    { dial: '+44',  flag: '🇬🇧', name: 'United Kingdom' },
    { dial: '+33',  flag: '🇫🇷', name: 'France' },
    { dial: '+49',  flag: '🇩🇪', name: 'Deutschland' },
    { dial: '+39',  flag: '🇮🇹', name: 'Italia' },
    { dial: '+31',  flag: '🇳🇱', name: 'Nederland' },
    { dial: '+32',  flag: '🇧🇪', name: 'Belgique' },
    { dial: '+41',  flag: '🇨🇭', name: 'Schweiz' },
    { dial: '+43',  flag: '🇦🇹', name: 'Österreich' },
    { dial: '+46',  flag: '🇸🇪', name: 'Sverige' },
    { dial: '+47',  flag: '🇳🇴', name: 'Norge' },
    { dial: '+45',  flag: '🇩🇰', name: 'Danmark' },
    { dial: '+358', flag: '🇫🇮', name: 'Suomi' },
    { dial: '+353', flag: '🇮🇪', name: 'Ireland' },
    { dial: '+352', flag: '🇱🇺', name: 'Luxembourg' },
    { dial: '+30',  flag: '🇬🇷', name: 'Ελλάδα' },
    // Eastern Europe
    { dial: '+40',  flag: '🇷🇴', name: 'România' },
    { dial: '+48',  flag: '🇵🇱', name: 'Polska' },
    { dial: '+380', flag: '🇺🇦', name: 'Україна' },
    { dial: '+420', flag: '🇨🇿', name: 'Česká republika' },
    { dial: '+421', flag: '🇸🇰', name: 'Slovensko' },
    { dial: '+36',  flag: '🇭🇺', name: 'Magyarország' },
    { dial: '+359', flag: '🇧🇬', name: 'България' },
    { dial: '+385', flag: '🇭🇷', name: 'Hrvatska' },
    { dial: '+381', flag: '🇷🇸', name: 'Srbija' },
    { dial: '+7',   flag: '🇷🇺', name: 'Россия' },
    { dial: '+373', flag: '🇲🇩', name: 'Moldova' },
    { dial: '+374', flag: '🇦🇲', name: 'Armenia' },
    { dial: '+995', flag: '🇬🇪', name: 'Georgia' },
    // North America
    { dial: '+1',   flag: '🇺🇸', name: 'USA / Canada' },
    // Latin America
    { dial: '+52',  flag: '🇲🇽', name: 'México' },
    { dial: '+57',  flag: '🇨🇴', name: 'Colombia' },
    { dial: '+54',  flag: '🇦🇷', name: 'Argentina' },
    { dial: '+56',  flag: '🇨🇱', name: 'Chile' },
    { dial: '+51',  flag: '🇵🇪', name: 'Perú' },
    { dial: '+58',  flag: '🇻🇪', name: 'Venezuela' },
    { dial: '+593', flag: '🇪🇨', name: 'Ecuador' },
    { dial: '+591', flag: '🇧🇴', name: 'Bolivia' },
    { dial: '+595', flag: '🇵🇾', name: 'Paraguay' },
    { dial: '+598', flag: '🇺🇾', name: 'Uruguay' },
    { dial: '+55',  flag: '🇧🇷', name: 'Brasil' },
    { dial: '+53',  flag: '🇨🇺', name: 'Cuba' },
    { dial: '+502', flag: '🇬🇹', name: 'Guatemala' },
    { dial: '+503', flag: '🇸🇻', name: 'El Salvador' },
    { dial: '+504', flag: '🇭🇳', name: 'Honduras' },
    { dial: '+505', flag: '🇳🇮', name: 'Nicaragua' },
    { dial: '+506', flag: '🇨🇷', name: 'Costa Rica' },
    { dial: '+507', flag: '🇵🇦', name: 'Panamá' },
    // North Africa
    { dial: '+212', flag: '🇲🇦', name: 'Maroc / المغرب' },
    { dial: '+213', flag: '🇩🇿', name: 'Algérie / الجزائر' },
    { dial: '+216', flag: '🇹🇳', name: 'Tunisie / تونس' },
    { dial: '+20',  flag: '🇪🇬', name: 'Egypt / مصر' },
    { dial: '+218', flag: '🇱🇾', name: 'Libya / ليبيا' },
    // Sub-Saharan Africa
    { dial: '+221', flag: '🇸🇳', name: 'Sénégal' },
    { dial: '+225', flag: '🇨🇮', name: "Côte d'Ivoire" },
    { dial: '+234', flag: '🇳🇬', name: 'Nigeria' },
    { dial: '+233', flag: '🇬🇭', name: 'Ghana' },
    { dial: '+251', flag: '🇪🇹', name: 'Ethiopia' },
    { dial: '+27',  flag: '🇿🇦', name: 'South Africa' },
    { dial: '+254', flag: '🇰🇪', name: 'Kenya' },
    { dial: '+255', flag: '🇹🇿', name: 'Tanzania' },
    { dial: '+256', flag: '🇺🇬', name: 'Uganda' },
    { dial: '+237', flag: '🇨🇲', name: 'Cameroun' },
    // Middle East
    { dial: '+966', flag: '🇸🇦', name: 'Saudi Arabia' },
    { dial: '+971', flag: '🇦🇪', name: 'UAE / الإمارات' },
    { dial: '+90',  flag: '🇹🇷', name: 'Türkiye' },
    { dial: '+98',  flag: '🇮🇷', name: 'Iran / ایران' },
    { dial: '+964', flag: '🇮🇶', name: 'Iraq / العراق' },
    { dial: '+962', flag: '🇯🇴', name: 'Jordan / الأردن' },
    { dial: '+961', flag: '🇱🇧', name: 'Lebanon / لبنان' },
    { dial: '+968', flag: '🇴🇲', name: 'Oman / عُمان' },
    { dial: '+974', flag: '🇶🇦', name: 'Qatar / قطر' },
    { dial: '+965', flag: '🇰🇼', name: 'Kuwait / الكويت' },
    // South Asia
    { dial: '+91',  flag: '🇮🇳', name: 'India / भारत' },
    { dial: '+92',  flag: '🇵🇰', name: 'Pakistan / پاکستان' },
    { dial: '+880', flag: '🇧🇩', name: 'Bangladesh / বাংলাদেশ' },
    { dial: '+977', flag: '🇳🇵', name: 'Nepal / नेपाल' },
    { dial: '+94',  flag: '🇱🇰', name: 'Sri Lanka / ශ්‍රී ලංකා' },
    { dial: '+93',  flag: '🇦🇫', name: 'Afghanistan / افغانستان' },
    // East Asia
    { dial: '+86',  flag: '🇨🇳', name: 'China / 中国' },
    { dial: '+81',  flag: '🇯🇵', name: 'Japan / 日本' },
    { dial: '+82',  flag: '🇰🇷', name: 'South Korea / 한국' },
    // Southeast Asia
    { dial: '+63',  flag: '🇵🇭', name: 'Philippines' },
    { dial: '+84',  flag: '🇻🇳', name: 'Việt Nam' },
    { dial: '+66',  flag: '🇹🇭', name: 'Thailand / ประเทศไทย' },
    { dial: '+62',  flag: '🇮🇩', name: 'Indonesia' },
    { dial: '+60',  flag: '🇲🇾', name: 'Malaysia' },
    { dial: '+65',  flag: '🇸🇬', name: 'Singapore' },
    // Oceania
    { dial: '+61',  flag: '🇦🇺', name: 'Australia' },
    { dial: '+64',  flag: '🇳🇿', name: 'New Zealand' },
];

// Longest-match parse so +351 wins over +1, +380 wins over +38, etc.
const SORTED = [...COUNTRIES].sort((a, b) => b.dial.length - a.dial.length);

function parse(value) {
    if (!value) return { dial: '+34', number: '' };
    const v = value.trim();
    for (const c of SORTED) {
        if (v.startsWith(c.dial)) {
            return { dial: c.dial, number: v.slice(c.dial.length).replace(/^\s+/, '') };
        }
    }
    return { dial: '+34', number: v };
}

const { dial: initDial, number: initNum } = parse(props.modelValue);
const dialCode = ref(initDial);
const numberPart = ref(initNum);
const dropOpen = ref(false);
const search = ref('');
const root = ref(null);

watch(() => props.modelValue, (val) => {
    const p = parse(val);
    dialCode.value = p.dial;
    numberPart.value = p.number;
});

function emitVal() {
    const n = numberPart.value.trim();
    emit('update:modelValue', n ? `${dialCode.value} ${n}` : '');
}

function onInput(e) {
    const val = e.target.value;
    if (val.startsWith('+')) {
        for (const c of SORTED) {
            if (val.startsWith(c.dial)) {
                dialCode.value = c.dial;
                numberPart.value = val.slice(c.dial.length).replace(/^\s+/, '');
                emitVal();
                return;
            }
        }
    }
    numberPart.value = val;
    emitVal();
}

function pick(c) {
    dialCode.value = c.dial;
    dropOpen.value = false;
    search.value = '';
    emitVal();
}

function openDrop() {
    dropOpen.value = true;
    search.value = '';
}

const currentCountry = computed(() => COUNTRIES.find(c => c.dial === dialCode.value) ?? COUNTRIES[0]);

const filtered = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return COUNTRIES;
    return COUNTRIES.filter(c =>
        c.name.toLowerCase().includes(q) || c.dial.includes(q)
    );
});

function onDocClick(e) {
    if (dropOpen.value && root.value && !root.value.contains(e.target)) {
        dropOpen.value = false;
        search.value = '';
    }
}
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div ref="root" class="flex">
        <!-- Country code button -->
        <div class="relative shrink-0">
            <button
                type="button"
                :disabled="disabled"
                class="flex h-full items-center gap-1.5 rounded-s-md border border-e-0 border-line-strong bg-surface-sunken px-2.5 py-2 text-sm transition hover:bg-surface-hover focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:opacity-50"
                @click.stop="openDrop">
                <span class="text-base leading-none">{{ currentCountry.flag }}</span>
                <span class="text-xs font-medium text-ink-soft">{{ currentCountry.dial }}</span>
                <AppIcon name="chevron-down" class="h-3 w-3 shrink-0 text-muted transition-transform"
                    :class="dropOpen ? 'rotate-180' : ''" />
            </button>

            <!-- Country list dropdown -->
            <transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="scale-95 opacity-0"
                enter-to-class="scale-100 opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="scale-100 opacity-100"
                leave-to-class="scale-95 opacity-0">
                <div v-if="dropOpen"
                    class="absolute start-0 top-full z-30 mt-1 w-60 origin-top rounded-lg border border-line bg-surface-raised shadow-raised">
                    <!-- Search box -->
                    <div class="border-b border-line p-2">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search country…"
                            class="w-full rounded-md border border-line-strong bg-surface-sunken px-2.5 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none"
                            @click.stop />
                    </div>
                    <!-- Country list -->
                    <div class="max-h-56 overflow-y-auto p-1">
                        <p v-if="filtered.length === 0" class="px-3 py-4 text-center text-sm text-muted">No results</p>
                        <button v-for="c in filtered" :key="c.dial + c.name"
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-left text-sm transition hover:bg-surface-hover"
                            :class="c.dial === dialCode ? 'font-medium text-accent' : 'text-ink'"
                            @click="pick(c)">
                            <span class="w-6 text-base leading-none">{{ c.flag }}</span>
                            <span class="flex-1 truncate">{{ c.name }}</span>
                            <span class="text-xs text-muted">{{ c.dial }}</span>
                        </button>
                    </div>
                </div>
            </transition>
        </div>

        <!-- Number input -->
        <input
            :value="numberPart"
            type="tel"
            :placeholder="placeholder || '612 345 678'"
            :disabled="disabled"
            class="min-w-0 flex-1 rounded-e-md border border-line-strong bg-surface-sunken px-3 py-2 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:opacity-50"
            @input="onInput" />
    </div>
</template>
