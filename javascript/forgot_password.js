document.addEventListener('DOMContentLoaded', () => {
    const pinInput = document.getElementById('reset-pin');
    const pinGrid = document.getElementById('pin-grid');
    const feedback = document.getElementById('pin-feedback');
    const continueButton = document.getElementById('continue-reset');

    if (!pinInput || !pinGrid || !feedback || !continueButton) return;

    const pinBoxes = Array.from(pinGrid.querySelectorAll('.pin-box'));

    let pending = null;

    const setFeedback = (message, isOk) => {
        if (!message) {
            feedback.style.display = 'none';
            feedback.textContent = '';
            return;
        }
        feedback.style.display = 'block';
        feedback.textContent = message;
        feedback.style.color = isOk ? '#166534' : '#a83232';
    };

    const setPinVerified = (isOk) => {
        continueButton.disabled = !isOk;
    };

    setPinVerified(false);

    const syncPin = () => {
        const pin = pinBoxes.map((box) => box.value.trim()).join('');
        pinInput.value = pin;
        return pin;
    };

    const handleChange = () => {
        const pin = syncPin();
        setFeedback('', false);
        setPinVerified(false);

        if (pending) {
            clearTimeout(pending);
        }

        if (pin.length < 6) {
            return;
        }

        pending = setTimeout(() => {
            const body = new URLSearchParams({ pin });
            fetch('check_reset_pin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then((response) => response.json())
            .then((data) => {
                const ok = !!data.ok;
                setFeedback(data.message || '', ok);
                setPinVerified(ok);
            })
            .catch(() => {
                setFeedback('Unable to verify PIN right now.', false);
                setPinVerified(false);
            });
        }, 250);
    };

    pinBoxes.forEach((box, index) => {
        box.addEventListener('input', (event) => {
            const value = event.target.value.replace(/\D/g, '').slice(0, 1);
            event.target.value = value;

            if (value && index < pinBoxes.length - 1) {
                pinBoxes[index + 1].focus();
            }

            handleChange();
        });

        box.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !event.target.value && index > 0) {
                pinBoxes[index - 1].focus();
            }
        });

        box.addEventListener('paste', (event) => {
            event.preventDefault();
            const pasted = (event.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            if (!pasted) return;
            pasted.split('').forEach((char, idx) => {
                if (pinBoxes[idx]) {
                    pinBoxes[idx].value = char;
                }
            });
            const nextIndex = Math.min(pasted.length, pinBoxes.length - 1);
            pinBoxes[nextIndex].focus();
            handleChange();
        });
    });
});
