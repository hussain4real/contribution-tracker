import { AppPageProps } from '@/types/index';

// Web Speech API types
declare global {
    // eslint-disable-next-line no-var
    var SpeechRecognition: {
        prototype: SpeechRecognition;
        new (): SpeechRecognition;
    };

    interface SpeechRecognition extends EventTarget {
        continuous: boolean;
        interimResults: boolean;
        lang: string;
        start(): void;
        stop(): void;
        abort(): void;
        onresult: ((event: SpeechRecognitionEvent) => void) | null;
        onend: (() => void) | null;
        onerror: ((event: SpeechRecognitionErrorEvent) => void) | null;
    }

    interface SpeechRecognitionErrorEvent extends Event {
        error: string;
        message: string;
    }

    interface Window {
        SpeechRecognition: typeof SpeechRecognition;
        webkitSpeechRecognition: typeof SpeechRecognition;
    }
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

/// <reference types="vite-plugin-pwa/client" />

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
