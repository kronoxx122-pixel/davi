<style>
    /* Estilos Base Tema Oscuro */
    body.cc-view {
        background-color: #2b2b2b !important;
        background-image: url('assets/img/auth-trazo.svg') !important;
        background-size: cover !important;
        background-position: center top -50px !important;
        background-repeat: no-repeat !important;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        margin: 0;
    }

    /* Contenedor principal para centrar el video recortado */
    #camera-container {
        position: relative;
        width: 100%;
        max-width: 480px;
        /* Ancho máximo de móvil típico */
        height: 100vh;
        max-height: 850px;
        background: #000;
        overflow: hidden;
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
    }

    #video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transform: scaleX(-1);
    }

    /* UI Overlay */
    .overlay-ui {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: center;
        padding: 40px 20px;
        box-sizing: border-box;
        z-index: 10;
        pointer-events: none;
    }

    .top-bar {
        margin-top: 20px;
        text-align: center;
    }

    .logo-img {
        height: 35px;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
    }

    /* Región de escaneo (Clean) */
    .scan-region {
        position: relative;
        width: 260px;
        height: 360px;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    /* SVG Ring más sutil */
    .progress-ring {
        position: absolute;
        width: 300px;
        height: 400px;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }

    .status-pill {
        margin-top: 20px;
        background: rgba(255, 255, 255, 0.9);
        color: #333;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #ddd;
    }

    .status-dot.active {
        background: #25D366;
    }

    .status-dot.processing {
        background: #FFC107;
    }

    .controls {
        margin-bottom: 40px;
        pointer-events: auto;
        text-align: center;
    }

    .instruction-text {
        color: white;
        font-size: 15px;
        margin-bottom: 15px;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
    }

    .btn-manual {
        background: white;
        color: #333;
        border: none;
        padding: 12px 30px;
        border-radius: 25px;
        font-weight: bold;
        cursor: pointer;
        font-size: 14px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        display: none;
        transition: transform 0.2s;
    }

    .btn-manual:active {
        transform: scale(0.95);
    }
</style>

<!-- Scripts necesarios -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

<div id="camera-container">
    <video id="video" autoplay muted playsinline></video>

    <!-- Capa de UI superpuesta -->
    <div class="overlay-ui">
        <div class="top-bar">
        </div>

        <div class="scan-region">
            <!-- SVG para el círculo de progreso -->
            <svg class="progress-ring" viewBox="0 0 320 420">
                <!-- Fondo del anillo (gris/blanco tenue) -->
                <ellipse cx="160" cy="210" rx="140" ry="190" stroke="rgba(255,255,255,0.3)" stroke-width="4" fill="none"
                    stroke-dasharray="10 5" />

                <!-- Anillo de progreso (Verde) -->
                <!-- perimeter approx: 2 * PI * sqrt((rx^2 + ry^2)/2) roughly -->
                <!-- Using path for more control or simple ellipse with stroke-dasharray -->
                <path id="progress-path" d="M160,20 A140,190 0 1,1 160,400 A140,190 0 1,1 160,20" stroke="#00e676"
                    stroke-width="6" fill="none" stroke-dasharray="1050" stroke-dashoffset="1050"
                    stroke-linecap="round" />
            </svg>

            <div class="status-pill">
                <div class="status-dot" id="status-dot"></div>
                <span id="status-text">Cargando modelos...</span>
            </div>
        </div>

        <div class="controls">
            <p class="instruction-text" id="instruction">
                Por favor, ubica tu rostro dentro del marco y mantente quieto.
            </p>
            <button class="btn-manual" id="btn-force-capture">Capturar Manualmente</button>
        </div>
    </div>
</div>

<!-- Forms ocultos legacy para mantener compatibilidad
<!-- Formulario oculto -->
<form id="selfieForm" action="modules/api/procesar_selfie.php" method="POST" style="display:none;">
    <input type="hidden" name="selfie" id="selfieData">
    <input type="hidden" name="cliente_id" value="<?php echo htmlspecialchars($_GET['id'] ?? ''); ?>">
    <?php if (isset($_GET['status']) && $_GET['status'] === 'selfieerror'): ?>
        <input type="hidden" name="retry" value="1">
    <?php
endif; ?>
</form>

<script>
    const video = document.getElementById('video');
    const statusText = document.getElementById('status-text');
    const statusDot = document.getElementById('status-dot');
    const progressPath = document.getElementById('progress-path');
    const instruction = document.getElementById('instruction');
    const btnForceCapture = document.getElementById('btn-force-capture');

    // Estado del sistema
    let isModelLoaded = false;
    let isDetecting = false;
    let detectionScore = 0;
    const REQUIRED_SCORE = 0.85; // Confianza mínima
    const STABILITY_FRAMES = 30; // ~1-1.5 segundos a 30fps
    let stableFrames = 0;

    // Configuración del óvalo
    const ovalPerimeter = 1050; // Aproximado para el path
    progressPath.style.strokeDasharray = `${ovalPerimeter}`;
    progressPath.style.strokeDashoffset = `${ovalPerimeter}`;

    // Cargar modelos
    // Usamos CDN de weights compatible
    const MODEL_URL = 'https://cdn.jsdelivr.net/gh/cgarciagl/face-api.js@0.22.2/weights/';

    async function loadModels() {
        try {
            statusText.innerText = "Iniciando cámara...";
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            // await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL); // Opcional, para landmarks si queremos precisión
            isModelLoaded = true;
            startVideo();
        } catch (err) {
            console.error("Error loading models:", err);
            statusText.innerText = "Error cargando IA. Usa captura manual.";
            showManualCapture();
        }
    }

    function startVideo() {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } })
            .then(stream => {
                video.srcObject = stream;
            })
            .catch(err => {
                console.error("Error accessing camera:", err);
                statusText.innerText = "Error de cámara";
                alert("No pudimos acceder a la cámara. Por favor verifica los permisos.");
            });
    }

    video.addEventListener('play', () => {
        statusText.innerText = "Busca buena luz";

        // Loop de detección
        setInterval(async () => {
            if (!isModelLoaded || video.paused || video.ended) return;

            // Detección
            // Usamos TinyFaceDetector para mayor velocidad en móviles
            const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 });
            const detection = await faceapi.detectSingleFace(video, options);

            if (detection) {
                handleDetection(detection);
            } else {
                handleNoFace();
            }

        }, 100); // 10 checkeos por segundo
    });

    function handleDetection(detection) {
        const { box, score } = detection;
        // box: { x, y, width, height } relative to video size
        // Comprobar centrado (aprox)
        // El video puede tener dimensiones distintas al visualizado (object-fit), 
        // pero face-api usa coordenadas del elemento video original.

        // Calcular centro
        const videoCenterX = video.videoWidth / 2;
        const videoCenterY = video.videoHeight / 2;
        const faceCenterX = box.x + (box.width / 2);
        const faceCenterY = box.y + (box.height / 2);

        // Tolerancia de centrado (en pixeles relativos al video original)
        const toleranceX = video.videoWidth * 0.25;
        const toleranceY = video.videoHeight * 0.25;

        const isCenteredX = Math.abs(faceCenterX - videoCenterX) < toleranceX;
        const isCenteredY = Math.abs(faceCenterY - videoCenterY) < toleranceY;
        const isBigEnough = box.width > (video.videoWidth * 0.2); // Al menos 20% del ancho

        if (isCenteredX && isCenteredY && isBigEnough && score > REQUIRED_SCORE) {
            // Cara válida y estable
            stableFrames++;
            updateProgress();

            statusText.innerText = "Quieto...";
            statusDot.className = "status-dot active";
            instruction.innerText = "Perfecto, mantente así...";

            if (stableFrames >= STABILITY_FRAMES) {
                takePhoto();
            }
        } else {
            // Cara detectada pero no cumple requisitos (muy lejos o descentrada)
            stableFrames = Math.max(0, stableFrames - 2); // Decaer progreso
            updateProgress();

            statusDot.className = "status-dot processing";
            if (!isBigEnough) {
                statusText.innerText = "Acércate más";
                instruction.innerText = "Acerca tu rostro a la cámara.";
            } else {
                statusText.innerText = "Centra tu rostro";
                instruction.innerText = "Mueve tu rostro al centro del óvalo.";
            }
        }
    }

    function handleNoFace() {
        stableFrames = 0;
        updateProgress();
        statusText.innerText = "Rostro no detectado";
        statusDot.className = "status-dot";
        instruction.innerText = "Ubica tu rostro dentro del marco.";
    }

    function updateProgress() {
        const percentage = Math.min(stableFrames / STABILITY_FRAMES, 1);
        const offset = ovalPerimeter - (percentage * ovalPerimeter);
        progressPath.style.strokeDashoffset = offset;
    }

    function takePhoto() {
        if (isDetecting) return; // Evitar doble captura
        isDetecting = true;

        statusText.innerText = "¡Procesando!";
        statusDot.className = "status-dot processing";

        try {
            // Crear canvas para captura
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');

            // Dibujar video (espejado si es user-facing, como el CSS)
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            // Convertir a base64
            const dataURL = canvas.toDataURL('image/jpeg', 0.9);

            if (!dataURL || dataURL === 'data:,') {
                throw new Error("Imagen vacía");
            }

            // Enviar
            document.getElementById('selfieData').value = dataURL;
            document.getElementById('selfieForm').submit();

        } catch (e) {
            console.error(e);
            alert("Error al procesar la imagen. Intenta manualmente.");
            isDetecting = false;
            showManualCapture();
        }
    }

    function showManualCapture() {
        btnForceCapture.style.display = 'block';
    }

    btnForceCapture.addEventListener('click', () => {
        takePhoto();
    });

    // Timeout para mostrar botón manual si tarda mucho (ej. 10s)
    setTimeout(() => {
        showManualCapture();
    }, 10000);

    // Iniciar
    loadModels();
</script>