let songCount = 0;

function selectText() {
    const element = document.querySelector("pre");
    const range = document.createRange();
    range.selectNodeContents(element);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
}

function copyText() {
    selectText();
    const text = document.getSelection().toString().trim();
    navigator.clipboard.writeText(text);
}

function shuffleLinks() {
    console.log("Shuffling links...");
    const pre = document.querySelector("pre");
    // Split the entire content of the pre element by newlines into an array
    const links = pre.textContent.split("\n");
    for (let i = links.length - 1; i > 0; i--) {
        // Generate a random index
        const j = Math.floor(Math.random() * (i + 1));
        // Swap elements at indices i and j
        [links[i], links[j]] = [links[j], links[i]];
    }
    // Join the shuffled links back together with newlines and set as the pre element's textContent
    pre.textContent = links.join("\n");
}



document.addEventListener("DOMContentLoaded", () => {
    const selectAllBtn = document.getElementById("selectAllBtn");
    const copyBtn = document.getElementById("copyBtn");
    const shuffleBtn = document.getElementById("shuffleBtn");
    const popup = document.getElementById("popup");

    selectAllBtn.addEventListener("click", selectText);
    copyBtn.addEventListener("click", copyText);
    shuffleBtn.addEventListener("click", shuffleLinks);
    copyBtn.addEventListener("click", () => {
        popup.style.display = "flex";
        popup.style.flexDirection = "column";
        popup.style.alignItems = "center";
        popup.style.justifyContent = "center";
        setTimeout(() => {
            popup.style.display = "none";
        }, 1000);
    });
});

document.addEventListener('DOMContentLoaded', function () {
    var dropzone = document.getElementById('dropzone');
    dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropzone.style.backgroundColor = '#ddd';
    });
    dropzone.addEventListener('dragleave', function (e) {
        e.preventDefault();
        dropzone.style.backgroundColor = '#f4f4f4';
    });
    dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.style.backgroundColor = '#f4f4f4';
        var file = e.dataTransfer.files[0];
        if (file.type === 'text/plain') { // Check if the dropped file is a text file
            var reader = new FileReader();
            reader.onload = function (e) {
                var fileContent = e.target.result;
                var links = fileContent.split('\n'); // Split the file content by new line to get an array of links
                songCount = links.length;
                // Output the links
                var pre = document.createElement('pre');
                pre.textContent = links.join('\n');
                document.body.appendChild(pre);

                // Enable the buttons
                document.getElementById('shuffleBtn').disabled = false;
                document.getElementById('selectAllBtn').disabled = false;
                document.getElementById('copyBtn').disabled = false;

                // Assuming you have a variable 'songCount' that holds the number of songs
                const songCounter = document.getElementById("songCounter");
                songCounter.innerText = songCount;
            };
            reader.readAsText(file);
        } else {
            alert('Please drop a text file.');
        }
    });
});

// check if pre element exists, if not keep the buttons disabled
document.addEventListener('DOMContentLoaded', function () {
    var pre = document.getElementsByTagName("pre")[0];
    if (pre === undefined) {
        document.getElementById('shuffleBtn').disabled = true;
        document.getElementById('selectAllBtn').disabled = true;
        document.getElementById('copyBtn').disabled = true;
    }
});