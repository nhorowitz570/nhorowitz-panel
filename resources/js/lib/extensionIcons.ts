import type { Component } from 'vue';
import {
    SolarBilling,
    SolarBuilding2,
    SolarCloud,
    SolarCode,
    SolarCreditCard,
    SolarDatabase,
    SolarFileText,
    SolarGift,
    SolarGlobe,
    SolarHistory,
    SolarKey,
    SolarLockKeyhole,
    SolarPalette,
    SolarPartners,
    SolarPulse,
    SolarServerCog,
    SolarSettings,
    SolarShieldCheck,
    SolarSparkles,
    SolarSupport,
    SolarUserRound,
    SolarUsers,
} from '@/lib/solarIcons';

const icons: Record<string, Component> = {
    billing: SolarBilling,
    building: SolarBuilding2,
    cloud: SolarCloud,
    code: SolarCode,
    'credit-card': SolarCreditCard,
    database: SolarDatabase,
    document: SolarFileText,
    gift: SolarGift,
    globe: SolarGlobe,
    history: SolarHistory,
    key: SolarKey,
    lock: SolarLockKeyhole,
    palette: SolarPalette,
    partners: SolarPartners,
    pulse: SolarPulse,
    server: SolarServerCog,
    settings: SolarSettings,
    shield: SolarShieldCheck,
    sparkles: SolarSparkles,
    support: SolarSupport,
    user: SolarUserRound,
    users: SolarUsers,
};

export function extensionIcon(name: string): Component {
    return icons[name] ?? SolarSettings;
}
