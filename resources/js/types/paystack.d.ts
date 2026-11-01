declare module '@paystack/inline-js' {
    interface PaystackCallbacks {
        onSuccess?: (transaction: Record<string, unknown>) => void;
        onCancel?: () => void;
        onClose?: () => void;
        onError?: (error: { message: string }) => void;
        onLoad?: (response: Record<string, unknown>) => void;
    }

    export default class PaystackPop {
        newTransaction(
            options: Record<string, unknown> & PaystackCallbacks,
        ): void;
        resumeTransaction(
            accessCode: string,
            callbacks?: PaystackCallbacks,
        ): void;
    }
}
