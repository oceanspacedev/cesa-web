import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pino from 'pino';
import QRCode from 'qrcode';
import makeWASocket, {
    Browsers,
    DisconnectReason,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    useMultiFileAuthState,
} from '@whiskeysockets/baileys';
import { createWhatsAppEngine } from './engine.mjs';
import { createRequestHandler } from './http-server.mjs';

const port = Number(process.env.REKRUTMEN_WA_ENGINE_PORT || 3318);
const host = process.env.REKRUTMEN_WA_ENGINE_HOST || '127.0.0.1';
const sessionRoot = process.env.REKRUTMEN_WA_SESSION_ROOT
    || path.join(path.dirname(fileURLToPath(import.meta.url)), 'sessions');
const logger = pino({ level: process.env.REKRUTMEN_WA_LOG_LEVEL || 'error' });
const engine = createWhatsAppEngine({
    sessionRoot,
    journalRoot: process.env.REKRUTMEN_WA_JOURNAL_ROOT || path.join(path.dirname(sessionRoot), 'whatsapp-messages'),
    maxReconnect: Number(process.env.REKRUTMEN_WA_MAX_RECONNECT || 8),
    makeSocket: makeWASocket,
    useAuthState: useMultiFileAuthState,
    fetchVersion: fetchLatestBaileysVersion,
    cacheKeys: makeCacheableSignalKeyStore,
    browser: Browsers.ubuntu('Chrome'),
    qrToDataURL: QRCode.toDataURL,
    disconnectReasons: DisconnectReason,
    logger,
});
const server = http.createServer(createRequestHandler(engine, { logger }));
let shutdownPromise;

async function shutdown(signal) {
    if (shutdownPromise) {
        return shutdownPromise;
    }

    logger.info({ signal }, 'Engine WhatsApp berhenti.');
    shutdownPromise = engine.shutdown().finally(() => {
        server.close(() => process.exit(0));
    });
    setTimeout(() => process.exit(0), 10000).unref();

    return shutdownPromise;
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('unhandledRejection', (error) => {
    logger.error({ err: error?.message || String(error) }, 'unhandledRejection');
});
server.on('error', (error) => {
    logger.error({ err: error.message }, 'Engine WhatsApp gagal membuka port.');
    process.exitCode = 1;
    engine.shutdown().finally(() => process.exit(1));
});
server.listen(port, host, () => {
    logger.info({ host, port, sessionRoot, pid: process.pid }, 'Rekrutmen WhatsApp engine ready');
    engine.restoreSessions().catch((error) => {
        logger.error({ err: error.message }, 'Pemulihan sesi WhatsApp gagal.');
    });
});
