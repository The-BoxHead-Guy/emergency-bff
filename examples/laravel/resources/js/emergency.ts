import { mount } from '@dataemergencia/emergency';

declare global {
  interface Window {
    __EMERGENCY__?: {
      code: string;
      bffBaseUrl: string;
      fallbackPhone: string;
    };
  }
}

const config = window.__EMERGENCY__;

if (config) {
  const client = mount({
    root: '#emergency-root',
    bffBaseUrl: config.bffBaseUrl,
    code: config.code,
    trigger: '#sos',
    fallback: {
      phone: config.fallbackPhone,
      label: 'Línea de emergencia',
    },
    onEvent: (event, payload) => {
      // Replace with your analytics or logging. Never log full phone numbers.
      console.info(`[emergency] ${event}`, payload);
    },
  });

  window.addEventListener('pagehide', () => client.destroy(), { once: true });
}
