export function accountReviewModal(wire, reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return {
        loading: false,
        failure: '',
        requested: Boolean(wire.recoveryModalOpen),
        closing: false,
        outsideStarted: false,
        revision: 0,
        animation: null,
        dialog: null,

        init() {
            this.dialog = this.$el;
        },

        show() {
            if (this.dialog.open) return;
            this.dialog.showModal();
            const reduced = reducedMotion();
            this.animation = this.dialog.animate([
                { opacity: 0, transform: reduced ? 'none' : 'translateY(12px) scale(.98)' },
                { opacity: 1, transform: 'none' },
            ], { duration: reduced ? 140 : 240, easing: 'cubic-bezier(.2,.7,.2,1)' });
            this.animation.finished.catch(() => {});
        },

        async open(id) {
            if (this.loading || this.closing) return;
            const revision = ++this.revision;
            this.requested = true;
            this.loading = true;
            this.failure = '';
            this.show();
            try {
                await wire.openRecovery(id);
            } catch {
                if (revision === this.revision) this.failure = 'No se pudo cargar la solicitud. Cierra el modal e inténtalo de nuevo.';
            } finally {
                if (revision === this.revision) this.loading = false;
                else if (!this.requested) Promise.resolve(wire.closeRecovery()).catch(() => {});
            }
        },

        sync(serverOpen) {
            if (this.loading || this.failure) return;
            if (serverOpen && this.requested) this.show();
            else if (!serverOpen && this.requested && this.dialog.open) this.close(false);
        },

        async close(notify = true) {
            if (this.closing || !this.dialog.open) return;
            this.closing = true;
            this.requested = false;
            ++this.revision;
            this.animation?.cancel();
            const reduced = reducedMotion();
            this.animation = this.dialog.animate([
                { opacity: 1, transform: 'none' },
                { opacity: 0, transform: reduced ? 'none' : 'translateY(8px) scale(.98)' },
            ], { duration: reduced ? 140 : 180, easing: 'ease-in', fill: 'forwards' });
            try { await this.animation.finished; } catch { /* A cancelled transition must still release the dialog. */ }
            this.dialog.close();
            this.animation.cancel();
            this.loading = this.closing = false;
            if (notify) Promise.resolve(wire.closeRecovery()).catch(() => {});
        },

        isOutside(event) {
            if (event.target !== this.dialog) return false;
            const rect = this.dialog.getBoundingClientRect();
            return event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
        },
    };
}

if (typeof window !== 'undefined') window.proportsaAccountReview = accountReviewModal;
