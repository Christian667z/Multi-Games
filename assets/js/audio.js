/**
 * Multi-Games - Moteur Sonore Web Audio API
 * Sons synthétisés rétro-gaming / néon sans aucun fichier audio externe requis
 */
const SoundEffects = (function() {
    let audioCtx = null;
    let isMuted = localStorage.getItem('mg_muted') === 'true';

    function initCtx() {
        if (!audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                audioCtx = new AudioContext();
            }
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    }

    function playTone(freq, type, duration, gainStart = 0.15, gainEnd = 0.001) {
        if (isMuted) return;
        try {
            initCtx();
            if (!audioCtx) return;

            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();

            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);

            gain.gain.setValueAtTime(gainStart, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(gainEnd, audioCtx.currentTime + duration);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            // Silencieux
        }
    }

    return {
        isMuted: () => isMuted,
        toggleMute: () => {
            isMuted = !isMuted;
            localStorage.setItem('mg_muted', isMuted);
            return isMuted;
        },
        click: () => {
            playTone(800, 'sine', 0.05, 0.08, 0.001);
        },
        hover: () => {
            playTone(450, 'triangle', 0.03, 0.03, 0.001);
        },
        playX: () => {
            if (isMuted) return;
            try {
                initCtx();
                const now = audioCtx.currentTime;
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(320, now);
                osc.frequency.exponentialRampToValueAtTime(640, now + 0.12);
                gain.gain.setValueAtTime(0.12, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.12);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(now + 0.12);
            } catch (e) {}
        },
        playO: () => {
            if (isMuted) return;
            try {
                initCtx();
                const now = audioCtx.currentTime;
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(580, now);
                osc.frequency.exponentialRampToValueAtTime(420, now + 0.14);
                gain.gain.setValueAtTime(0.15, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.14);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(now + 0.14);
            } catch (e) {}
        },
        win: () => {
            if (isMuted) return;
            const notes = [440, 554.37, 659.25, 880];
            notes.forEach((freq, idx) => {
                setTimeout(() => {
                    playTone(freq, 'triangle', 0.25, 0.15, 0.001);
                }, idx * 90);
            });
        },
        tie: () => {
            if (isMuted) return;
            playTone(280, 'sine', 0.2, 0.12, 0.001);
            setTimeout(() => playTone(220, 'sine', 0.3, 0.12, 0.001), 120);
        }
    };
})();
