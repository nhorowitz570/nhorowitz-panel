<script>
    (function () {
        let slow;
        try {
            slow = sessionStorage.getItem('so-no-gpu');
        } catch (e) {}

        if (slow === null || slow === undefined) {
            slow = '0';
            try {
                const gl = document.createElement('canvas').getContext('webgl', { failIfMajorPerformanceCaveat: true });
                if (!gl) {
                    slow = '1';
                } else {
                    const info = gl.getExtension('WEBGL_debug_renderer_info');
                    const renderer = info ? String(gl.getParameter(info.UNMASKED_RENDERER_WEBGL)) : '';
                    if (/swiftshader|llvmpipe|softpipe|software|basic render/i.test(renderer)) {
                        slow = '1';
                    }
                    const lose = gl.getExtension('WEBGL_lose_context');
                    if (lose) lose.loseContext();
                }
            } catch (e) {}
            try {
                sessionStorage.setItem('so-no-gpu', slow);
            } catch (e) {}
        }

        if (slow === '1') {
            document.documentElement.classList.add('no-gpu');
        }
    })();
</script>
