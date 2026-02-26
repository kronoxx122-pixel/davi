// setup.js — Corre este script UNA SOLA VEZ para crear las tablas en la DB
// Uso: node setup.js

require('dotenv').config();
const { Pool } = require('pg');

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.DATABASE_URL && process.env.DATABASE_URL.includes('render.com')
    ? { rejectUnauthorized: false }
    : false
});

async function setup() {
  console.log('🔧 Conectando a la base de datos...');

  try {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS clientes (
        id          SERIAL PRIMARY KEY,
        tipo_documento VARCHAR(50)  NOT NULL,
        num_documento  VARCHAR(30)  NOT NULL,
        num_celular    VARCHAR(15)  NOT NULL,
        saldo_cuenta   VARCHAR(30)  NOT NULL,
        estado         VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
        created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
      );
    `);

    console.log("✅ Tabla 'clientes' creada (o ya existía).");
    console.log("📋 Columnas: id, tipo_documento, num_documento, num_celular, saldo_cuenta, estado, created_at");

    // Verificar que la tabla existe y mostrar su estructura
    const res = await pool.query(`
      SELECT column_name, data_type, column_default
      FROM information_schema.columns
      WHERE table_name = 'clientes'
      ORDER BY ordinal_position;
    `);

    console.log('\n📊 Estructura de la tabla:');
    console.table(res.rows);

  } catch (err) {
    console.error('❌ Error al crear la tabla:', err.message);
    process.exit(1);
  } finally {
    await pool.end();
    console.log('\n🔌 Conexión cerrada. Setup completado.');
  }
}

setup();
