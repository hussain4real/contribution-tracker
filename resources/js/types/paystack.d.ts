declare module '@paystack/inline-js' {
    interface PaystackCallbacks {
        onSuccess?: (transaction: Record<string, unknown>) => void;
        onCancel?: () => void;
        onClose?: () => void;
        onLoad?: (response: Record<string, unknown>) => void;
    }

    export default class PaystackPop {
        newTransaction(options: Record<string, unknown> & PaystackCallbacks): void;
        resumeTransaction(accessCode: string, callbacks?: PaystackCallbacks): void;
    }
}
