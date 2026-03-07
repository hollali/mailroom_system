<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <header class="bg-white shadow-sm border-b border-gray-200">
    <div class="flex items-center justify-between px-6 py-4">
        <div class="flex items-center flex-1">
            <button class="text-gray-500 focus:outline-none lg:hidden">
                <i class="fas fa-bars text-xl"></i>
            </button>
            
            <div class="relative mx-4 flex-1 max-w-lg">
                <input type="text" placeholder="Quick search..." 
                       class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all">
                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            </div>
        </div>
        
        <div class="flex items-center space-x-4">
            <button class="relative p-2 text-gray-400 hover:text-gray-600">
                <i class="fas fa-bell text-xl"></i>
                <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
            </button>
            
            <div class="flex items-center space-x-3">
                <div class="text-right hidden sm:block">
                    <p class="text-sm font-medium text-gray-900"><?php echo date('F j, Y'); ?></p>
                    <p class="text-xs text-gray-500"><?php echo date('l'); ?></p>
                </div>
                
                <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-500 flex items-center justify-center text-white">
                    <i class="fas fa-user-circle text-2xl"></i>
                </div>
            </div>
        </div>
    </div>
</header>
</body>
</html>