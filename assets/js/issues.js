// Shared issue options
const issueOptions = {
  Phone: [
    "Cracked screen","Back glass cracked","Battery drains fast","Charging port not working",
    "Liquid damage","Camera blurry / not working","Speaker or mic issue",
    "Face ID / Touch ID issue","No signal / SIM not detected","Wi-Fi/Bluetooth issue",
    "Touchscreen unresponsive","Buttons not working"
  ],
  Laptop: [
    "No power / won’t turn on","Blue screen / frequent crashes","Overheating / loud fan",
    "Slow performance","Storage / hard disk failure","Keyboard/trackpad not working",
    "Broken hinge / chassis","No display / GPU artifacts","USB/ports not working",
    "Operating system / software issue","Virus / malware infection","Data recovery needed"
  ],
  Tablet: [
    "Cracked screen","Battery issue","Charging problem","Touchscreen unresponsive",
    "Wi-Fi not working","Camera issue","Slow performance","App crashes"
  ],
  Desktop: [
    "No power","Blue screen","No display","Slow performance",
    "Hard disk failure","Overheating","Fan noise","USB/ports not working"
  ],
  Printer: [
    "Paper jam","Not printing","Ink/toner issue","Connectivity problem",
    "Error codes","Lines/streaks on prints","Slow printing"
  ],
  Peripheral: [
    "Not detected by computer","Connection issues","Button failure","Driver/software issue"
  ],
  Other: [
    "Unidentified issue","Custom hardware","General maintenance","Diagnostics needed"
  ]
};

/**
 * Render selectable chips and write to textarea
 * @param {string} deviceType
 * @param {string} textareaId
 * @param {string} chipBoxId
 */
function renderChips(deviceType, textareaId, chipBoxId) {
  const chipBox = document.getElementById(chipBoxId);
  const textarea = document.getElementById(textareaId);
  if (!chipBox || !textarea) return;

  chipBox.innerHTML = "";

  (issueOptions[deviceType] || []).forEach(txt => {
    const span = document.createElement("span");
    span.className = "chip";
    span.textContent = txt;
    span.onclick = () => {
      chipBox.querySelectorAll(".chip").forEach(c => c.classList.remove("active"));
      span.classList.add("active");
      textarea.value = txt;
      textarea.focus();
    };
    chipBox.appendChild(span);
  });
}

window.NF_ISSUES = { renderChips, issueOptions };
