/**
 * ADF System - Push Notification Manager
 * Handles browser notifications and push subscriptions
 */

class NotificationManager {
    constructor() {
        this.isSupported = 'Notification' in window;
        this.isServiceWorkerSupported = 'serviceWorker' in navigator;
        this.permission = this.isSupported ? Notification.permission : 'denied';
        this.swRegistration = null;
    }

    /**
     * Initialize notification system
     */
    async init() {
        if (!this.isSupported) {
            console.warn('Browser tidak mendukung notifikasi');
            return false;
        }

        // Register service worker
        if (this.isServiceWorkerSupported) {
            try {
                this.swRegistration = await navigator.serviceWorker.register('/sw.js');
                console.log('Service Worker registered');
            } catch (error) {
                console.error('Service Worker registration failed:', error);
            }
        }

        return true;
    }

    /**
     * Request notification permission
     */
    async requestPermission() {
        if (!this.isSupported) {
            return { success: false, message: 'Browser tidak mendukung notifikasi' };
        }

        if (this.permission === 'granted') {
            return { success: true, message: 'Notifikasi sudah diaktifkan' };
        }

        if (this.permission === 'denied') {
            return { 
                success: false, 
                message: 'Notifikasi diblokir. Silakan aktifkan di pengaturan browser.' 
            };
        }

        try {
            const permission = await Notification.requestPermission();
            this.permission = permission;
            
            if (permission === 'granted') {
                this.showNotification('🔔 Notifikasi Aktif', {
                    body: 'Anda akan menerima notifikasi saat ada end-shift atau event penting lainnya.',
                    icon: '/assets/img/logo.png'
                });
                return { success: true, message: 'Notifikasi berhasil diaktifkan!' };
            }
            
            return { success: false, message: 'Izin notifikasi ditolak' };
        } catch (error) {
            return { success: false, message: 'Gagal meminta izin: ' + error.message };
        }
    }

    /**
     * Show notification
     */
    async showNotification(title, options = {}) {
        if (this.permission !== 'granted') {
            console.warn('Notification permission not granted');
            return false;
        }

        const defaultOptions = {
            icon: '/assets/img/logo.png',
            badge: '/assets/img/badge.png',
            vibrate: [200, 100, 200],
            requireInteraction: true,
            tag: 'adf-notification-' + Date.now(),
            ...options
        };

        try {
            if (this.swRegistration) {
                await this.swRegistration.showNotification(title, defaultOptions);
            } else {
                new Notification(title, defaultOptions);
            }
            return true;
        } catch (error) {
            console.error('Failed to show notification:', error);
            return false;
        }
    }

    /**
     * Show end-shift notification
     */
    async showEndShiftNotification(data) {
        const title = '📊 Laporan End Shift';
        const options = {
            body: `${data.cashier_name} telah selesai shift\n` +
                  `Total Penjualan: Rp ${this.formatNumber(data.total_sales || 0)}\n` +
                  `Kas Akhir: Rp ${this.formatNumber(data.ending_cash || 0)}`,
            icon: '/assets/img/logo.png',
            badge: '/assets/img/badge.png',
            tag: 'end-shift-' + (data.shift_id || Date.now()),
            data: {
                url: '/modules/reports/shift-report.php?id=' + (data.shift_id || ''),
                type: 'end-shift'
            },
            actions: [
                { action: 'view', title: '📋 Lihat Laporan' },
                { action: 'dismiss', title: '✖️ Tutup' }
            ],
            requireInteraction: true,
            vibrate: [300, 100, 300, 100, 300]
        };

        return this.showNotification(title, options);
    }

    /**
     * Show new booking notification
     */
    async showBookingNotification(data) {
        const title = '🏨 Reservasi Baru';
        const options = {
            body: `Tamu: ${data.guest_name}\n` +
                  `Kamar: ${data.room_number}\n` +
                  `Check-in: ${data.check_in_date}`,
            icon: '/assets/img/logo.png',
            tag: 'booking-' + (data.booking_id || Date.now()),
            data: {
                url: '/modules/frontdesk/index.php',
                type: 'booking'
            }
        };

        return this.showNotification(title, options);
    }

    /**
     * Show income notification
     */
    async showIncomeNotification(data) {
        const title = '💰 Pendapatan Baru';
        const options = {
            body: `${data.description}\n` +
                  `Jumlah: Rp ${this.formatNumber(data.amount || 0)}`,
            icon: '/assets/img/logo.png',
            tag: 'income-' + Date.now(),
            data: {
                url: '/modules/cashbook/',
                type: 'income'
            }
        };

        return this.showNotification(title, options);
    }

    /**
     * Format number with thousand separator
     */
    formatNumber(num) {
        return new Intl.NumberFormat('id-ID').format(num);
    }

    /**
     * Check if notifications are enabled
     */
    isEnabled() {
        return this.permission === 'granted';
    }

    /**
     * Get notification status
     */
    getStatus() {
        return {
            supported: this.isSupported,
            serviceWorkerSupported: this.isServiceWorkerSupported,
            permission: this.permission,
            enabled: this.permission === 'granted'
        };
    }
}

// Create global instance
window.NotificationManager = new NotificationManager();

// Auto-initialize when DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.NotificationManager.init();
});

// Helper function to enable notifications
async function enableNotifications() {
    const result = await window.NotificationManager.requestPermission();
    return result;
}

// Helper function to send end-shift notification
async function sendEndShiftNotification(data) {
    return await window.NotificationManager.showEndShiftNotification(data);
}
