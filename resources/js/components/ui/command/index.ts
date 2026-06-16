import type { InjectionKey, Ref } from 'vue';
import { inject, provide } from 'vue';

type CommandFilterState = {
    search: string;
    filtered: {
        count: number;
        items: Map<string, number>;
        groups: Set<string>;
    };
};

type CommandContext = {
    allItems: Ref<Map<string, string>>;
    allGroups: Ref<Map<string, Set<string>>>;
    filterState: CommandFilterState;
};

type CommandGroupContext = {
    id: string;
};

const commandContextKey: InjectionKey<CommandContext> =
    Symbol('commandContext');
const commandGroupContextKey: InjectionKey<CommandGroupContext> = Symbol(
    'commandGroupContext',
);

export function provideCommandContext(context: CommandContext): void {
    provide(commandContextKey, context);
}

export function useCommand(): CommandContext {
    const context = inject(commandContextKey);

    if (!context) {
        throw new Error('Command components must be used inside Command.');
    }

    return context;
}

export function provideCommandGroupContext(
    context: CommandGroupContext,
): void {
    provide(commandGroupContextKey, context);
}

export function useCommandGroup(): CommandGroupContext | undefined {
    return inject(commandGroupContextKey);
}
