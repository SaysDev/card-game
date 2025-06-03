import { ref } from 'vue';

interface ToastOptions {
  title: string;
  description?: string;
  variant?: 'default' | 'destructive' | 'success';
  duration?: number;
}

interface Toast extends ToastOptions {
  id: string;
}

const toasts = ref<Toast[]>([]);

export function useToast() {
  const toast = (options: ToastOptions) => {
    const id = Math.random().toString(36).substring(2, 9);
    const newToast: Toast = {
      id,
      title: options.title,
      description: options.description,
      variant: options.variant || 'default',
      duration: options.duration || 5000,
    };

    toasts.value.push(newToast);

    setTimeout(() => {
      dismiss(id);
    }, newToast.duration);

    return id;
  };

  const dismiss = (id: string) => {
    toasts.value = toasts.value.filter(toast => toast.id !== id);
  };

  return {
    toasts,
    toast,
    dismiss
  };
}
