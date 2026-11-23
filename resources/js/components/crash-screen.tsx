import { router } from '@inertiajs/react';
import { Component, type ErrorInfo, type ReactNode } from 'react';

interface State {
    error: Error | null;
}

/**
 * Last line of defence: if a screen crashes, show what happened and a way out instead of a blank page.
 * Moving to another page clears it, so one broken screen never takes the whole app down.
 */
export class CrashScreen extends Component<{ children: ReactNode }, State> {
    state: State = { error: null };
    private stopListening?: () => void;

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidMount() {
        this.stopListening = router.on('navigate', () => {
            if (this.state.error) this.setState({ error: null });
        });
    }

    componentWillUnmount() {
        this.stopListening?.();
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error('Screen crashed', error, info.componentStack);
    }

    render() {
        if (!this.state.error) return this.props.children;

        return (
            <div className="flex min-h-svh items-center justify-center p-6">
                <div className="max-w-md space-y-3 text-center">
                    <h1 className="text-xl font-semibold">This screen hit a problem</h1>
                    <p className="text-muted-foreground text-sm">Nothing you saved is affected. Reload to carry on.</p>
                    <p className="text-muted-foreground font-mono text-xs break-words">{this.state.error.message}</p>
                    <div className="flex justify-center gap-2">
                        <button className="bg-foreground text-background rounded-md px-4 py-2 text-sm" onClick={() => window.location.reload()}>
                            Reload
                        </button>
                        <a className="rounded-md border px-4 py-2 text-sm" href="/dashboard">
                            Overview
                        </a>
                    </div>
                </div>
            </div>
        );
    }
}
