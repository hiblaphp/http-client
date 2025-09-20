<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Feedback</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --color-primary-accent: #6d28d9;
            --color-primary-text: #4c1d95;
            --color-bg-main: #f5f3ff;
            --color-bg-active: #ede9fe;
            --color-secondary-accent: #3b82f6;
            --color-border-soft: #ddd6fe;
        }

        .bg-main {
            background-color: var(--color-bg-main);
        }

        .bg-active {
            background-color: var(--color-bg-active);
        }

        .text-primary-accent {
            color: var(--color-primary-accent);
        }

        .text-primary-text {
            color: var(--color-primary-text);
        }

        .border-soft {
            border-color: var(--color-border-soft);
        }

        .btn-primary {
            background-color: var(--color-primary-accent);
            color: white;
            transition: background-color 0.3s ease-in-out;
        }

        .btn-primary:hover {
            background-color: var(--color-primary-text);
        }

        .btn-secondary {
            background-color: var(--color-secondary-accent);
            color: white;
            transition: background-color 0.3s ease-in-out;
        }

        .btn-secondary:hover {
            opacity: 0.9;
        }

        /* Animation Keyframes */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes bounce {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.2);
            }
        }

        /* Applying animations */
        .fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }

        .bounce-on-click {
            animation: bounce 0.3s ease-in-out;
        }
    </style>
</head>

<body class="bg-main font-sans leading-normal tracking-normal">

    <div class="min-h-screen flex items-center justify-center p-4 sm:p-6 lg:p-8">
        <div class="max-w-2xl w-full bg-white rounded-lg shadow-2xl p-6 sm:p-8 transform transition-all"
            x-data="{ rating: 0, hoverRating: 0, submitted: false }"
            x-init="$el.classList.add('fade-in')">

            <div x-show="!submitted">
                <div class="text-center mb-6 sm:mb-8">
                    <h1 class="text-2xl sm:text-3xl font-bold text-primary-text">We'd love your feedback!</h1>
                    <p class="text-gray-600 mt-2 text-sm sm:text-base">Help us improve by sharing your thoughts.</p>
                </div>

                <form @submit.prevent="submitted = true">
                    <div class="mb-6">
                        <label for="opinion" class="block text-sm font-medium text-gray-700 mb-2">Your Opinion</label>
                        <textarea id="opinion" name="opinion" rows="4"
                            class="w-full px-4 py-2 text-gray-700 bg-white border border-soft rounded-md 
                                         focus:outline-none focus:ring-2 focus:ring-primary-accent transition-shadow duration-300"
                            placeholder="Tell us what you think..."></textarea>
                    </div>

                    <div class="mb-8">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Your Rating</label>
                        <div class="flex items-center justify-center space-x-1 sm:space-x-2" @mouseleave="hoverRating = 0">
                            <template x-for="star in 5">
                                <svg @mouseenter="hoverRating = star"
                                    @click="rating = star; $el.classList.add('bounce-on-click'); setTimeout(() => $el.classList.remove('bounce-on-click'), 300)"
                                    class="w-10 h-10 sm:w-12 sm:h-12 cursor-pointer transition-transform duration-200 transform hover:scale-110"
                                    :class="{
                                        'text-yellow-400': hoverRating >= star || rating >= star,
                                        'text-gray-300': hoverRating < star && rating < star
                                     }"
                                    fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                            </template>
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="w-full sm:w-auto btn-primary font-bold py-3 px-6 rounded-lg">
                            Submit Feedback
                        </button>
                    </div>
                </form>
            </div>

            <div x-show="submitted" class="text-center fade-in" x-cloak>
                <svg class="w-16 h-16 mx-auto mb-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h2 class="text-2xl font-bold text-primary-text">Thank You!</h2>
                <p class="text-gray-600 mt-2">Your feedback has been received.</p>
            </div>

        </div>
    </div>

</body>

</html>